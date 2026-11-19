<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\CampanhaDevolucao;
use App\Contribua\Entity\CampanhaParticipacao;
use App\Contribua\Repository\CampanhaDevolucaoRepository;
use App\Contribua\Repository\CampanhaParticipacaoRepository;
use App\Contribua\Service\CampanhaVigente;
use App\Contribua\Service\CofreDaChavePixDaCampanha;
use App\Contribua\Service\FormatoDaCampanha;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Não gostei": a pessoa pede a devolução do Desafio, em dobro ou triplo, dentro do prazo contado do pagamento.
 * A chave Pix fica cifrada. O valor e o prazo são os congelados na inscrição, não os da campanha de hoje.
 */
final class PedirDevolucaoDaCampanhaUseCase
{
    public const MAXIMO_DA_CHAVE_PIX = 140;

    public function __construct(
        private readonly CampanhaVigente $vigente,
        private readonly CampanhaParticipacaoRepository $participacoes,
        private readonly CampanhaDevolucaoRepository $devolucoes,
        private readonly CofreDaChavePixDaCampanha $cofre,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return string a mensagem de sucesso */
    public function executar(Solicitante $quem, string $pix, ?string $motivo): string
    {
        $campanha = $this->vigente->campanha();
        $minha = $campanha === null ? null : $this->participacoes->daPessoa($campanha, $quem->usuario);
        if ($minha === null) {
            throw new \DomainException('Fora do prazo ou sem inscrição ativa.');
        }
        $pix = trim($pix);
        if ($pix === '') {
            throw new \DomainException('Informe a chave Pix para receber.');
        }
        if (mb_strlen($pix) > self::MAXIMO_DA_CHAVE_PIX) {
            throw new \DomainException('A chave Pix pode ter até 140 caracteres.');
        }
        $hoje = $this->vigente->hoje();
        $agora = $this->vigente->agora();

        $participacao = $this->em->wrapInTransaction(function () use ($minha, $pix, $motivo, $hoje, $agora): CampanhaParticipacao {
            // Trava a linha: dois cliques ao mesmo tempo não criam dois pedidos.
            $travada = $this->em->find(CampanhaParticipacao::class, $minha->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$travada instanceof CampanhaParticipacao || !$travada->podeDevolver($hoje)) {
                throw new \DomainException('Fora do prazo ou sem inscrição ativa.');
            }
            $travada->registrarPedidoDeDevolucao();
            $this->devolucoes->salvar(new CampanhaDevolucao($travada, $travada->valorDaDevolucaoCentavos(), $this->cofre->cifrar($pix), $motivo, $agora));

            return $travada;
        });

        return 'Pedido de devolução registrado. Você recebe ' . FormatoDaCampanha::reais($participacao->valorDaDevolucaoCentavos())
            . ' por Pix em até ' . $participacao->getPrazoPagamentoDiasUteis() . ' dias úteis.';
    }
}
