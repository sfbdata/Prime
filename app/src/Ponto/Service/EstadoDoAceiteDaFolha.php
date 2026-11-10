<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AceiteDaFolhaOutput;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\DadosDaFolhaEmitida;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Exception\AssinaturaDaFolhaRecusadaException;
use App\Ponto\Repository\AceiteDaFolhaDoPontoRepository;

/**
 * O aceite da folha de uma competência da pessoa NESTE escritório (spec ponto-desenho-0811, E16; decisões 1, 3, 4 e
 * 16; desenho 16 L816): o aceite que vale (o de maior número), se a folha mudou depois dele e se a pessoa pode assinar.
 * (O enum {@see \App\Ponto\Enum\SituacaoDoAceiteDaFolha} é a resposta de um aceite; este serviço é o estado da folha.)
 *
 * "Alterada depois do aceite" compara a impressão dos registros guardada DENTRO dos dados assinados da folha emitida
 * vista no aceite com a da folha de agora (decisão 3). Com o selo pronto, os dados assinados só valem depois de o HMAC
 * conferir: a folha vista que não confere (texto mexido, versão de chave perdida) conta como alterada, porque o aceite
 * não prova mais que a pessoa viu esta folha. Sem o segredo não há como conferir; a comparação usa o texto guardado,
 * para o selo da barra e o carimbo do PDF continuarem (seção 7 da spec).
 *
 * Pode assinar (decisão 1 e desenho L816): a função `ponto-aceite` vale e o selo está pronto, o mês está fechado pelo
 * relógio do servidor, a competência está na lista da folha da pessoa (do mês do início da contagem dela neste
 * escritório em diante, P23) e não há aceite que valha (sem aceite, ou o último com a folha alterada).
 */
final class EstadoDoAceiteDaFolha
{
    public function __construct(
        private readonly AceiteDaFolhaDoPontoRepository $aceites,
        private readonly SeloDaFolha $selo,
        private readonly FuncaoDoAceite $funcao,
        private readonly InicioContagemResolver $inicioDaContagem,
        private readonly ConteudoDaFolhaDoMes $conteudos,
        private readonly RelogioDaFolha $relogio,
    ) {
    }

    /** Para a barra da folha: monta a folha de agora e avalia. */
    public function daCompetencia(Tenant $escritorio, User $pessoa, int $ano, int $mes): AceiteDaFolhaOutput
    {
        $agora = $this->relogio->agora();

        return $this->avaliar($escritorio, $pessoa, $this->conteudos->montar($escritorio, $pessoa, $ano, $mes, $agora), $agora);
    }

    /** Avalia sobre a folha já montada (quem emite ou assina não monta duas vezes). */
    public function avaliar(Tenant $escritorio, User $pessoa, ConteudoDaFolha $folhaDeAgora, \DateTimeImmutable $agora): AceiteDaFolhaOutput
    {
        $vigente = $this->aceites->ultimoDaCompetencia($escritorio, $pessoa, $folhaDeAgora->ano, $folhaDeAgora->mes);
        $alterada = $vigente !== null && $this->alteradaDepois($vigente, $folhaDeAgora);
        $impedimento = $this->impedimento($escritorio, $pessoa, $folhaDeAgora, $agora, $vigente !== null && !$alterada);

        return AceiteDaFolhaOutput::daCompetencia($folhaDeAgora->ano, $folhaDeAgora->mes, $vigente, $alterada, $impedimento);
    }

    /** O aceite que vai carimbado na folha: o que vale, se a folha não mudou depois dele; senão, null. */
    public function aceiteQueCarimba(Tenant $escritorio, User $pessoa, ConteudoDaFolha $folhaDeAgora): ?AceiteDaFolhaDoPonto
    {
        $vigente = $this->aceites->ultimoDaCompetencia($escritorio, $pessoa, $folhaDeAgora->ano, $folhaDeAgora->mes);

        return $vigente !== null && !$this->alteradaDepois($vigente, $folhaDeAgora) ? $vigente : null;
    }

    private function alteradaDepois(AceiteDaFolhaDoPonto $aceite, ConteudoDaFolha $folhaDeAgora): bool
    {
        $vista = $aceite->getFolhaVista();
        if ($this->selo->disponivel() && !$this->selo->conferir($vista->getDadosAssinados(), $vista->getHmac(), $vista->getVersaoChave())) {
            return true;
        }
        try {
            $impressaoVista = DadosDaFolhaEmitida::deCanonico($vista->getDadosAssinados())->impressaoRegistros;
        } catch (\UnexpectedValueException) {
            return true;
        }

        return !hash_equals($impressaoVista, $folhaDeAgora->impressaoDosRegistros());
    }

    private function impedimento(Tenant $escritorio, User $pessoa, ConteudoDaFolha $folha, \DateTimeImmutable $agora, bool $jaAssinada): ?string
    {
        $competencia = sprintf('%04d-%02d', $folha->ano, $folha->mes);

        return match (true) {
            !$this->funcao->vale($escritorio)                          => AssinaturaDaFolhaRecusadaException::FUNCAO_DESLIGADA,
            $competencia >= $agora->format('Y-m')                      => AssinaturaDaFolhaRecusadaException::MES_ABERTO,
            !$this->naFolhaDaPessoa($escritorio, $pessoa, $competencia) => AssinaturaDaFolhaRecusadaException::FORA_DA_FOLHA,
            $jaAssinada                                                => AssinaturaDaFolhaRecusadaException::JA_ASSINADA,
            default                                                    => null,
        };
    }

    /** A competência está na lista da folha da pessoa: do mês do início da contagem dela NESTE escritório em diante. */
    private function naFolhaDaPessoa(Tenant $escritorio, User $pessoa, string $competencia): bool
    {
        $inicio = $this->inicioDaContagem->resolver($pessoa, $escritorio);

        return $inicio !== null && $competencia >= $inicio->format('Y-m');
    }
}
