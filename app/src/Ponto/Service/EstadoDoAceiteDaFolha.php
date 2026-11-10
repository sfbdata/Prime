<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AceiteDaFolhaOutput;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\DadosDaFolhaEmitida;
use App\Ponto\DTO\DadosDoAceiteDaFolha;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Exception\AssinaturaDaFolhaRecusadaException;
use App\Ponto\Exception\CompetenciaDaFolhaInvalidaException;
use App\Ponto\Repository\AceiteDaFolhaDoPontoRepository;

/**
 * O aceite da folha de uma competência da pessoa NESTE escritório (spec ponto-desenho-0811, E16; decisões 1, 3, 4 e
 * 16; desenho 16 L816): o aceite que vale (o de maior número), se a folha mudou depois dele e se a pessoa pode assinar.
 * (O enum {@see \App\Ponto\Enum\SituacaoDoAceiteDaFolha} é a resposta de um aceite; este serviço é o estado da folha.)
 *
 * "Alterada depois do aceite" compara a impressão dos registros guardada DENTRO dos dados assinados da folha emitida
 * vista no aceite com a da folha de agora (decisão 3). Com o selo pronto, o aceite só vale depois de conferir a prova
 * inteira: o HMAC da folha vista, o HMAC do próprio aceite (decisão 14), a linha do aceite igual ao texto que ele selou
 * (a situação, a ressalva, o instante, o IP, o navegador, o escritório, a pessoa, a competência e o número) e a folha
 * ligada igual à que ele selou (o código selado nela e na coluna, o escritório, a pessoa e a competência selados nela).
 * O que não confere (texto mexido, coluna trocada, FK apontada para outra emissão, versão de chave perdida) conta como
 * alterada: o aceite não prova mais o que a pessoa assinou, não vai carimbado nem selado numa folha nova, e o "Assinar
 * folha" volta. Sem o segredo não há como conferir; vale o que está gravado, para o selo da barra e o carimbo do PDF
 * continuarem (seção 7 da spec).
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

    /**
     * Para a barra da folha: monta a folha de agora e avalia. Competência fora do calendário (mês fora de 1 a 12, ano
     * sem quatro dígitos): sem aceite e sem poder assinar, com a recusa do mês que não está na folha (decisão 24), sem
     * montar a folha, que não existe para esse mês.
     */
    public function daCompetencia(Tenant $escritorio, User $pessoa, int $ano, int $mes): AceiteDaFolhaOutput
    {
        try {
            FolhaEmitidaDoPonto::exigirCompetencia($ano, $mes);
        } catch (CompetenciaDaFolhaInvalidaException) {
            return AceiteDaFolhaOutput::daCompetencia($ano, $mes, null, false, AssinaturaDaFolhaRecusadaException::FORA_DA_FOLHA);
        }
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

    /**
     * O aceite que vai carimbado e selado na folha: o que vale, se a folha não mudou depois dele e (com o selo pronto) a
     * prova dele confere; senão, null.
     */
    public function aceiteQueCarimba(Tenant $escritorio, User $pessoa, ConteudoDaFolha $folhaDeAgora): ?AceiteDaFolhaDoPonto
    {
        $vigente = $this->aceites->ultimoDaCompetencia($escritorio, $pessoa, $folhaDeAgora->ano, $folhaDeAgora->mes);

        return $vigente !== null && !$this->alteradaDepois($vigente, $folhaDeAgora) ? $vigente : null;
    }

    private function alteradaDepois(AceiteDaFolhaDoPonto $aceite, ConteudoDaFolha $folhaDeAgora): bool
    {
        try {
            $vista = DadosDaFolhaEmitida::deCanonico($aceite->getFolhaVista()->getDadosAssinados());
        } catch (\UnexpectedValueException) {
            return true;
        }
        if ($this->selo->disponivel() && !$this->provaConfere($aceite, $vista)) {
            return true;
        }

        return !hash_equals($vista->impressaoRegistros, $folhaDeAgora->impressaoDosRegistros());
    }

    /**
     * Com o selo pronto: o HMAC da folha vista, o HMAC do aceite, a linha do aceite igual ao que ele selou e a folha
     * ligada igual à que ele selou ({@see self::folhaLigadaIgualASelada()}).
     */
    private function provaConfere(AceiteDaFolhaDoPonto $aceite, DadosDaFolhaEmitida $vista): bool
    {
        $folha = $aceite->getFolhaVista();
        if (!$this->selo->conferir($folha->getDadosAssinados(), $folha->getHmac(), $folha->getVersaoChave())
            || !$this->selo->conferir($aceite->getDadosAssinados(), $aceite->getHmac(), $aceite->getVersaoChave())) {
            return false;
        }
        try {
            $selado = DadosDoAceiteDaFolha::deCanonico($aceite->getDadosAssinados());
        } catch (\UnexpectedValueException) {
            return false;
        }

        return self::linhaIgualAoSelado($aceite, $selado) && self::folhaLigadaIgualASelada($folha->getCodigo(), $vista, $selado);
    }

    /**
     * As colunas do aceite dizem o mesmo que o texto selado nele (decisão 14). O instante compara o relógio de parede:
     * a coluna não guarda o fuso, e o texto selado guarda (o fuso do servidor, {@see RelogioDaFolha}).
     */
    private static function linhaIgualAoSelado(AceiteDaFolhaDoPonto $aceite, DadosDoAceiteDaFolha $selado): bool
    {
        return [
            $selado->situacao, $selado->ressalva, $selado->aceitoEm->format('Y-m-d H:i:s'), $selado->ip, $selado->navegador,
            $selado->escritorioId, $selado->pessoaId, $selado->ano, $selado->mes, $selado->numero,
        ] === [
            $aceite->getSituacao(), $aceite->getRessalva(), $aceite->getAceitoEm()->format('Y-m-d H:i:s'), $aceite->getIp(), $aceite->getNavegador(),
            $aceite->getTenant()?->getId(), $aceite->getUser()->getId(), $aceite->getAno(), $aceite->getMes(), $aceite->getNumero(),
        ];
    }

    /**
     * A folha ligada pela FK é a que o aceite selou: o código selado no aceite é o código SELADO na folha ligada (o texto
     * cujo HMAC acabou de conferir, como na validação pública) e também o da coluna dela, e o escritório, a pessoa e a
     * competência selados na folha são os selados no aceite. A coluna `codigo` sozinha não prova nada: trocá-la e
     * apontar a FK para outra emissão não pode passar.
     */
    private static function folhaLigadaIgualASelada(string $codigoDaColuna, DadosDaFolhaEmitida $vista, DadosDoAceiteDaFolha $selado): bool
    {
        return [$vista->codigo, $codigoDaColuna, $vista->escritorioId, $vista->pessoaId, $vista->ano, $vista->mes]
            === [$selado->codigoDaFolhaVista, $selado->codigoDaFolhaVista, $selado->escritorioId, $selado->pessoaId, $selado->ano, $selado->mes];
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
