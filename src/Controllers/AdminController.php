<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AdminController {

    public function dashboard(Request $req, Response $res): Response {
        $db = getDB();
        $surveys = $db->query("SELECT * FROM surveys ORDER BY created_at DESC")->fetchAll(\PDO::FETCH_ASSOC);
        $view = Twig::fromRequest($req);
        return $view->render($res, 'admin/dashboard.twig', ['surveys' => $surveys]);
    }

    public function uploadForm(Request $req, Response $res): Response {
        $view = Twig::fromRequest($req);
        return $view->render($res, 'admin/upload.twig', [
            'error'   => $_SESSION['upload_error'] ?? null,
            'success' => $_SESSION['upload_success'] ?? null,
        ]);
    }

    public function upload(Request $req, Response $res): Response {
        $data  = $req->getParsedBody();
        $files = $req->getUploadedFiles();
        $topic = trim($data['topic_name'] ?? '');
        $file  = $files['csv_file'] ?? null;

        unset($_SESSION['upload_error'], $_SESSION['upload_success']);

        if (!$topic || !$file || $file->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['upload_error'] = 'Please provide a topic name and a valid CSV file.';
            return $res->withHeader('Location', '/admin/upload')->withStatus(302);
        }

        // Generate unique slug
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $topic)) . '-' . substr(md5(uniqid()), 0, 6);

        // Read CSV
        $csvContent = (string) $file->getStream();
        $lines = array_filter(explode("\n", $csvContent));
        $questions = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            // Handle quoted CSV properly
            $cols = str_getcsv($line);
            if (count($cols) < 3) continue;

            $questionText   = trim($cols[0]);
            $correctAnswer  = trim($cols[1]);
            $wrongOptions   = array_map('trim', array_slice($cols, 2));
            $wrongOptions   = array_filter($wrongOptions); // remove empty

            if ($questionText && $correctAnswer && count($wrongOptions) > 0) {
                $questions[] = [
                    'question'      => $questionText,
                    'correct'       => $correctAnswer,
                    'wrong_options' => array_values($wrongOptions),
                ];
            }
        }

        if (empty($questions)) {
            $_SESSION['upload_error'] = 'No valid questions found in the CSV file.';
            return $res->withHeader('Location', '/admin/upload')->withStatus(302);
        }

        // Save to DB
        $db = getDB();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO surveys (topic_name, unique_slug) VALUES (?, ?)");
            $stmt->execute([$topic, $slug]);
            $surveyId = $db->lastInsertId();

            $qStmt = $db->prepare("INSERT INTO questions (survey_id, question_text, correct_answer, wrong_options) VALUES (?, ?, ?, ?)");
            foreach ($questions as $q) {
                $qStmt->execute([$surveyId, $q['question'], $q['correct'], json_encode($q['wrong_options'])]);
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            $_SESSION['upload_error'] = 'Database error: ' . $e->getMessage();
            return $res->withHeader('Location', '/admin/upload')->withStatus(302);
        }

        $_SESSION['upload_success'] = "Survey created! URL: /git_backend/survey_system/public/index.php/survey/{$slug}";
        return $res->withHeader('Location', '/git_backend/survey_system/public/index.php/admin/upload')->withStatus(302);
    }

    public function toggle(Request $req, Response $res, array $args): Response {
        $db = getDB();
        $stmt = $db->prepare("UPDATE surveys SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$args['id']]);
       return $res->withHeader('Location', '/git_backend/survey_system/public/index.php/admin/dashboard')->withStatus(302);
    }

    public function results(Request $req, Response $res, array $args): Response {
        $db = getDB();

        $survey = $db->prepare("SELECT * FROM surveys WHERE id = ?");
        $survey->execute([$args['id']]);
        $survey = $survey->fetch(\PDO::FETCH_ASSOC);

        $questions = $db->prepare("SELECT * FROM questions WHERE survey_id = ?");
        $questions->execute([$args['id']]);
        $questions = $questions->fetchAll(\PDO::FETCH_ASSOC);

        $responses = $db->prepare("SELECT * FROM responses WHERE survey_id = ? ORDER BY submitted_at DESC");
        $responses->execute([$args['id']]);
        $responses = $responses->fetchAll(\PDO::FETCH_ASSOC);

        // Decode answers JSON
        foreach ($responses as &$r) {
            $r['answers'] = json_decode($r['answers'], true);
        }

        $view = Twig::fromRequest($req);
        return $view->render($res, 'admin/results.twig', [
            'survey'    => $survey,
            'questions' => $questions,
            'responses' => $responses,
        ]);
    }

    public function download(Request $req, Response $res, array $args): Response {
        $db = getDB();

        $survey = $db->prepare("SELECT * FROM surveys WHERE id = ?");
        $survey->execute([$args['id']]);
        $survey = $survey->fetch(\PDO::FETCH_ASSOC);

        $questions = $db->prepare("SELECT * FROM questions WHERE survey_id = ? ORDER BY id");
        $questions->execute([$args['id']]);
        $questions = $questions->fetchAll(\PDO::FETCH_ASSOC);

        $responses = $db->prepare("SELECT * FROM responses WHERE survey_id = ? ORDER BY submitted_at");
        $responses->execute([$args['id']]);
        $responses = $responses->fetchAll(\PDO::FETCH_ASSOC);

        // Build CSV
        $output = fopen('php://temp', 'r+');

        // Header row
        $headers = ['Response ID', 'Submitted At', 'Score'];
        foreach ($questions as $q) {
            $headers[] = $q['question_text'];
        }
        fputcsv($output, $headers);

        // Data rows
        foreach ($responses as $r) {
            $answers = json_decode($r['answers'], true);
            $row = [$r['id'], $r['submitted_at'], $r['score']];
            foreach ($questions as $q) {
                $row[] = $answers[$q['id']] ?? 'N/A';
            }
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $filename = 'results_' . $survey['unique_slug'] . '_' . date('Ymd') . '.csv';
        $res->getBody()->write($csv);
        return $res
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }
}