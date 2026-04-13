<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SurveyController {

    public function show(Request $req, Response $res, array $args): Response {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM surveys WHERE unique_slug = ? AND is_active = 1");
        $stmt->execute([$args['slug']]);
        $survey = $stmt->fetch(\PDO::FETCH_ASSOC);

        $view = Twig::fromRequest($req);
        if (!$survey) {
            return $view->render($res, 'survey/questionnaire.twig', ['inactive' => true]);
        }

        $qStmt = $db->prepare("SELECT * FROM questions WHERE survey_id = ?");
        $qStmt->execute([$survey['id']]);
        $questions = $qStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Shuffle options for display
        foreach ($questions as &$q) {
            $wrong = json_decode($q['wrong_options'], true);
            $options = array_merge([$q['correct_answer']], $wrong);
            shuffle($options);
            $q['options'] = $options;
        }

        return $view->render($res, 'survey/questionnaire.twig', [
            'survey'    => $survey,
            'questions' => $questions,
            'inactive'  => false,
        ]);
    }

    public function submit(Request $req, Response $res, array $args): Response {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM surveys WHERE unique_slug = ? AND is_active = 1");
        $stmt->execute([$args['slug']]);
        $survey = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$survey) {
            $view = Twig::fromRequest($req);
            return $view->render($res, 'survey/questionnaire.twig', ['inactive' => true]);
        }

        $qStmt = $db->prepare("SELECT * FROM questions WHERE survey_id = ?");
        $qStmt->execute([$survey['id']]);
        $questions = $qStmt->fetchAll(\PDO::FETCH_ASSOC);

        $submitted = $req->getParsedBody();
        $answers = [];
        $score = 0;

        foreach ($questions as $q) {
            $key = 'q_' . $q['id'];
            $userAnswer = $submitted[$key] ?? null;
            $answers[$q['id']] = $userAnswer;
            if ($userAnswer === $q['correct_answer']) {
                $score++;
            }
        }

        // Save response
        $rStmt = $db->prepare("INSERT INTO responses (survey_id, answers, score) VALUES (?, ?, ?)");
        $rStmt->execute([$survey['id'], json_encode($answers), $score]);

        $view = Twig::fromRequest($req);
        return $view->render($res, 'survey/questionnaire.twig', [
            'submitted' => true,
            'score'     => $score,
            'total'     => count($questions),
            'survey'    => $survey,
        ]);
    }
}