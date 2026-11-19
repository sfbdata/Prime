<?php

declare(strict_types=1);

namespace App\Blog\MessageHandler;

use App\Blog\Message\EnviarArtigoPorEmail;
use App\Blog\UseCase\EnviarArtigoPorEmailUseCase;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class EnviarArtigoPorEmailHandler
{
    public function __construct(private readonly EnviarArtigoPorEmailUseCase $enviar)
    {
    }

    public function __invoke(EnviarArtigoPorEmail $mensagem): void
    {
        $this->enviar->executar($mensagem->artigoId);
    }
}
