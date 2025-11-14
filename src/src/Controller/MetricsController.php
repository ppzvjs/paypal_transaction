<?php

namespace App\Controller;

use Prometheus\RenderTextFormat;
use Prometheus\CollectorRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class MetricsController extends AbstractController
{
    public function metrics(CollectorRegistry $registry): Response
    {
        $renderer = new RenderTextFormat();
        $result = $renderer->render($registry->getMetricFamilySamples());

        return new Response($result, 200, [
            'Content-Type' => RenderTextFormat::MIME_TYPE
        ]);
    }
}
