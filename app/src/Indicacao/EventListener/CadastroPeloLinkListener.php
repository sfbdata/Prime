<?php

declare(strict_types=1);

namespace App\Indicacao\EventListener;

use App\Auth\Event\ContaCriadaPeloCadastro;
use App\Indicacao\Controller\IndicacaoController;
use App\Indicacao\Service\CodigoDeIndicacao;
use App\Indicacao\UseCase\AtribuirCadastroPeloLinkUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Liga a conta criada pelo "Criar minha conta" a quem indicou, quando a pessoa chegou pelo link curto `/i/{codigo}`
 * (spec `indicar-bluejus.md`, comportamento 7). O código mora na sessão (gravado pelo {@see IndicacaoController}); sai
 * dela sempre que existe, usado ou não. O Auth não conhece a Indicação: só despacha o evento, depois do commit.
 *
 * Cupom do "Criar conta" (desenho 10 VS 25.10.26, spec `login-cupom-e-facebook.md`, PR A): quando o JSON do POST traz
 * `indicacao`, o campo é a palavra final e vale no lugar do código da sessão (vazio ou malformado não liga nada). O
 * ouvinte lê o corpo da própria requisição do cadastro, para o Auth continuar sem conhecer a Indicação.
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
            $cupom = self::cupomDoCorpo($request);
            if ($cupom === false && !$sessao->has(IndicacaoController::CHAVE_DA_SESSAO)) {
                return;
            }
            $codigo = $sessao->remove(IndicacaoController::CHAVE_DA_SESSAO);
            $this->atribuir->executar($cupom !== false ? $cupom : (is_string($codigo) ? $codigo : null), $evento->userId);
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao ligar a conta criada pelo link curto a quem indicou.', [
                'exception' => $e,
                'userId' => $evento->userId,
            ]);
        }
    }

    /** O cupom do JSON do cadastro, sem o `BJ-` (nulo se vazio ou malformado); falso quando o corpo não traz `indicacao`. */
    private static function cupomDoCorpo(Request $request): string|false|null
    {
        try {
            $dados = $request->toArray();
        } catch (\Throwable) {
            return false;
        }

        return array_key_exists('indicacao', $dados) ? CodigoDeIndicacao::doCupom($dados['indicacao']) : false;
    }
}
