<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Enviar teste" (dc L.559; spec §8.4, C4): o e-mail montado como a campanha vai SÓ ao Master logado, pelo Carteiro,
 * `lista` `comercial_campanhas`, origem `teste_campanha` (fora do `campanha:` do Monitoramento), no layout do modo.
 * Isento do teto diário (`EnviarEmailHandler::ORIGENS_ISENTAS_DO_TETO_DIARIO`), sujeito ao limite por minuto, e com
 * teto próprio de 10 por hora por Master (contado no registro dos envios, que sobrevive a deploy). Quem se
 * descadastrou da lista não recebe: o Carteiro registra o bloqueio e a tela diz por quê.
 */
final class EnviarTesteDaCampanhaUseCase
{
    public const ORIGEM = 'teste_campanha';
    public const TETO_POR_HORA = 10;
    public const TETO_ATINGIDO = 'Limite de 10 testes por hora atingido.';
    public const DESCADASTRADO = 'Você está descadastrado da lista de campanhas; o teste não foi enviado.';

    public function __construct(
        private readonly ComposicaoDaCampanha $composicao,
        private readonly CarteiroInterface $carteiro,
        private readonly EnvioDeEmailRepository $envios,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(MensagemDaCampanha $mensagem, User $master): string
    {
        $para = (string) $master->getEmail();
        $desde = $this->relogio->now()->modify('-1 hour');
        if ($this->envios->contarDaOrigemParaDesde(self::ORIGEM, $para, $desde) >= self::TETO_POR_HORA) {
            throw new CampanhaRecusada(self::TETO_ATINGIDO);
        }
        $email = $this->composicao->mensagemPara($mensagem, $para, $master->getFullName(), self::ORIGEM);
        if (!$this->carteiro->enviar($email)->foiEnfileirado()) {
            throw new CampanhaRecusada(self::DESCADASTRADO);
        }

        return 'Teste enviado para ' . $email->para . '.';
    }
}
