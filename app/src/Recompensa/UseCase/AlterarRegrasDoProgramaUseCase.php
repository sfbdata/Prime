<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Recompensa\DTO\AlterarRegrasDoProgramaInput;
use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\ProgramaDeRecompensaRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Muda a regra geral do programa "Indique e ganhe" (chave ativo/pausado, recompensa, "libera com", carência, alerta
 * antifraude e "só conta escritório com pastas abertas"; desenho 05 Perfis 1.2 VS 25.10.26).
 *
 * Quem dispara: o Master, no bloco "Regras do programa" da seção Recompensas (ROLE_SUPER_ADMIN no controller).
 * Pré-condições: as seis regras informadas e dentro dos intervalos da entidade.
 * Fluxo principal: carrega a linha única (ou cria), grava as seis regras, registra quem alterou, no escopo de plataforma.
 * Não mexe nas condições individuais, que não mudam com a regra geral. Nada é recalculado nem gravado por indicador.
 * Fluxos alternativos: regra faltando ou fora do intervalo recusa (422), sem gravar.
 * Auditoria: ProgramaDeRecompensa é Auditavel; cada mudança entra na trilha do Master.
 */
final class AlterarRegrasDoProgramaUseCase
{
    public function __construct(
        private readonly ProgramaDeRecompensaRepository $programas,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(AlterarRegrasDoProgramaInput $input): void
    {
        if ($input->ativo === null || $input->valorCentavos === null || $input->minEscritorios === null
            || $input->carenciaDias === null || $input->alertaQtd === null || $input->exigePasta === null) {
            throw new RecompensaRecusada('Informe todas as regras do programa.');
        }

        $programa = $this->programas->buscarUnico() ?? new ProgramaDeRecompensa();
        $programa->alterarRegras($input->ativo, $input->valorCentavos, $input->minEscritorios, $input->carenciaDias, $input->alertaQtd, $input->exigePasta);
        $programa->registrarAlteracao($input->ator, $this->relogio->now());

        $this->escopoDePlataforma->executar(fn () => $this->programas->salvar($programa, flush: true));
    }
}
