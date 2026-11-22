<?php

declare(strict_types=1);

namespace App\DlqDoctor\Agent;

interface DlqDoctorAgentInterface
{
    public function analyze(DlqDoctorAgentRequest $request): DlqDoctorAgentResult;
}
