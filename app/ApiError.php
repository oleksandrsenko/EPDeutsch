<?php
class ApiError extends Exception
{
    public int $status;
    public array $extra;
    public function __construct(int $status, string $msg, array $extra = [])
    {
        parent::__construct($msg);
        $this->status = $status;
        $this->extra = $extra;
    }
}
