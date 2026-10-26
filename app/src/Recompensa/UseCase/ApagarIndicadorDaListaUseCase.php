<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\ApagarIndicadorDaListaInput;
use App\Recompensa\Entity\CondicaoDeRecompensa;
use App\Recompensa\Exception\IndicadorNaoEncontrado;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * "Apagar da lista" (menu ⋮; desenho 05 Perfis 1.2 VS 25.10.26, diálogo `apagar`; spec
 * perfis-1.2-vs-25-10-recompensas-e-planos, F3). O indicador sai da lista e dos KPIs; o extrato continua guardado.
 *
 * Quem dispara: o Master (ROLE_SUPER_ADMIN no controller). Dado de plataforma, sem tenant: o indicador é qualquer usuário
 * que já indicou; o id vem da URL e é conferido contra as indicações.
 * Fluxo principal: exige a palavra APAGAR (sem diferenciar maiúscula nem espaço nas pontas, como o desenho), também no
 * servidor, e marca a linha da condição como apagada. Não há tela para desfazer nesta fatia: nova indicação não o traz de
 * volta (pendência anotada na spec).
 * Idempotência: a trava das indicações do usuário serializa pedidos simultâneos (sem duas linhas); apagar quem já está apagado não muda nada nem regrava.
 * Fluxos alternativos: palavra errada ou ausente (422, sem gravar); indicador inexistente (404).
 * Auditoria: CondicaoDeRecompensa é Auditavel; cada mudança entra na trilha do Master.
 */
final class ApagarIndicadorDaListaUseCase
{
    private const PALAVRA = 'APAGAR';

    public function __construct(
        private readonly CondicaoDeRecompensaRepository $condicoes,
        private readonly IndicacaoRepository $indicacoes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    /**
     * @throws IndicadorNaoEncontrado
     * @throws RecompensaRecusada
     */
    public function executar(ApagarIndicadorDaListaInput $input): void
    {
        if (mb_strtoupper(trim($input->confirmacao)) !== self::PALAVRA) {
            throw new RecompensaRecusada('Digite APAGAR para confirmar.');
        }
        if ($this->indicacoes->contarDoUsuario($input->userId) === 0) {
            throw new IndicadorNaoEncontrado('Indicador não encontrado.');
        }
        $usuario = $this->em->find(User::class, $input->userId) ?? throw new IndicadorNaoEncontrado('Indicador não encontrado.');

        $this->escopoDePlataforma->executar(fn () => $this->indicacoes->emTransacaoTravada($input->userId, function () use ($input, $usuario): void {
            $condicao = $this->condicoes->buscarDoIndicador($input->userId);
            if ($condicao?->isApagada()) {
                return;
            }
            $condicao ??= new CondicaoDeRecompensa($usuario);
            $agora = $this->relogio->now();
            $condicao->apagar($agora);
            $condicao->registrarAlteracao($input->ator, $agora);
            $this->condicoes->salvar($condicao, flush: true);
        }));
    }
}
