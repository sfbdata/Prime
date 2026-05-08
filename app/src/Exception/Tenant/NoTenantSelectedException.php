<?php
declare(strict_types=1);

namespace App\Exception\Tenant;

final class NoTenantSelectedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Nenhum escritório selecionado na sessão.');
    }
}
