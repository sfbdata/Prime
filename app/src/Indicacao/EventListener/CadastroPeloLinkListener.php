<?php

declare(strict_types=1);

namespace App\Indicacao\EventListener;

use App\Auth\Event\ContaCriadaPeloCadastro;
use App\Indicacao\Controller\IndicacaoController;
use App\Indicacao\UseCase\AtribuirCadastroPeloLinkUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Liga a conta criada pelo "Criar minha conta" a quem indicou, quando a pessoa chegou pelo link curto `/i/{codigo}`
 * (spec `indicar-bluejus.md`, comportamento 7). O código mora na sessão (gravado pelo {@see IndicacaoController}); sai
 * dela sempre que existe, usado ou não. O Auth não conhece a Indicação: só despacha o evento, depois do commit.
 *
 * Nunca derruba o cadastro: qualquer falha vai ao log só com o id da conta nova.
 */
final class CadastroPeloLinkListener
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly AtribuirCadastroPeloLinkUseCase $atribuir,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function aoCriarConta(ContaCriadaPeloCadastro $evento): void
    {
        try {
            $request = $this->requests->getCurrentRequest();
            if ($request === null || !$request->hasSession()) {
                return;
            }
            $sessao = $request->getSession();
            if (!$sessao->has(IndicacaoController::CHAVE_DA_SESSAO)) {
                return;
            }
            $codigo = $sessao->remove(IndicacaoController::CHAVE_DA_SESSAO);
            $this->atribuir->executar(is_string($codigo) ? $codigo : null, $evento->userId);
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao ligar a conta criada pelo link curto a quem indicou.', [
                'exception' => $e,
                'userId' => $evento->userId,
            ]);
        }
    }
}
