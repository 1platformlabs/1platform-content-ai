<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/JobStatus.php';

class ContaiJob
{
    private $id;
    private $job_type;
    private $status;
    private $payload;
    private $priority;
    private $attempts;
    private $max_attempts;
    private $error_message;
    private $created_at;
    private $updated_at;
    private $processed_at;

    public function __construct()
    {
        $this->status = ContaiJobStatus::PENDING;
        $this->priority = 0;
        $this->attempts = 0;
        $this->max_attempts = 3;
        $this->created_at = current_time('mysql');
        $this->updated_at = current_time('mysql');
    }

    public static function create($job_type, array $payload, $priority = 0)
    {
        $job = new self();
        $job->job_type = $job_type;
        $job->payload = json_encode($payload);
        $job->priority = $priority;
        return $job;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getJobType()
    {
        return $this->job_type;
    }

    public function setJobType($job_type)
    {
        $this->job_type = $job_type;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        if (!ContaiJobStatus::isValid($status)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new InvalidArgumentException("Invalid job status: {$status}");
        }
        $this->status = $status;
        $this->updated_at = current_time('mysql');
    }

    public function getPayload()
    {
        return json_decode($this->payload, true);
    }

    public function setPayload($payload)
    {
        $this->payload = is_string($payload) ? $payload : json_encode($payload);
    }

    public function getPriority()
    {
        return $this->priority;
    }

    public function setPriority($priority)
    {
        $this->priority = $priority;
    }

    public function getAttempts()
    {
        return $this->attempts;
    }

    public function incrementAttempts()
    {
        $this->attempts++;
        $this->updated_at = current_time('mysql');
    }

    public function getMaxAttempts()
    {
        return $this->max_attempts;
    }

    public function setMaxAttempts($max_attempts)
    {
        $this->max_attempts = $max_attempts;
    }

    public function hasReachedMaxAttempts()
    {
        return $this->attempts >= $this->max_attempts;
    }

    public function getErrorMessage()
    {
        return $this->error_message;
    }

    public function setErrorMessage($error_message)
    {
        $this->error_message = $error_message;
        $this->updated_at = current_time('mysql');
    }

    public function getCreatedAt()
    {
        return $this->created_at;
    }

    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
    }

    public function getUpdatedAt()
    {
        return $this->updated_at;
    }

    public function setUpdatedAt($updated_at)
    {
        $this->updated_at = $updated_at;
    }

    public function getProcessedAt()
    {
        return $this->processed_at;
    }

    public function setProcessedAt($processed_at)
    {
        $this->processed_at = $processed_at;
        $this->updated_at = current_time('mysql');
    }

    public function markAsProcessing()
    {
        $this->setStatus(ContaiJobStatus::PROCESSING);
        $this->setProcessedAt(current_time('mysql'));
    }

    public function markAsCompleted()
    {
        $this->setStatus(ContaiJobStatus::DONE);
    }

    public function markAsFailed($error_message = null)
    {
        $this->setStatus(ContaiJobStatus::FAILED);
        if ($error_message) {
            $this->setErrorMessage($error_message);
        }
    }

    public function toArray()
    {
        return [
            'id' => $this->id,
            'job_type' => $this->job_type,
            'status' => $this->status,
            'payload' => $this->payload,
            'priority' => $this->priority,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'processed_at' => $this->processed_at
        ];
    }
}
