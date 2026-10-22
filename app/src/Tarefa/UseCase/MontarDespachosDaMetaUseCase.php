<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Pasta\Service\MencoesDoRegistro;
use App\Tarefa\DTO\DespachoNaLinhaDoTempoOutput;
use App\Tarefa\DTO\DespachosDaMetaOutput;
use App\Tarefa\DTO\FormularioDoDespachoOutput;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Service\QuemCuidaDaMeta;

/**
 * Monta, para a tela da meta, o bloco DESPACHO e os despachos da linha do tempo (desenho 04B de 22/10, L57-71,
 * L118, L159-161; spec `minhas-metas-esteira-2210.md`, C11 a C15).
 *
 * Prazo padrão: 5 dias úteis depois de hoje pela regra do escritório (C1, C12), com a UF do escritório. A lista
 * de dias não úteis cobre {@see JANELA_EM_DIAS} dias, para o navegador recontar o "N dias úteis" pela mesma regra.
 * A numeração ("DESPACHO N") é do histórico inteiro, no `MontarLinhaDoTempoDaMetaUseCase`.
 *
 * "quem → para" (L161) sai das @menções do texto (C3); o anexo juntado à pasta (C4) é lido numa consulta só, presa
 * ao escritório e à pasta da meta.
 */
final class MontarDespachosDaMetaUseCase
{
    public const UTEIS_DO_PRAZO_PADRAO = 5;
    public const JANELA_EM_DIAS = 180;

    public function __construct(
        private readonly DiasUteisDoEscritorio $diasUteis,
        private readonly QuemCuidaDaMeta $quemCuida,
        private readonly PastaDocumentoRepository $documentos,
    ) {
    }

    public function executar(Tarefa $meta, User $quemVe, Tenant $escritorio, \DateTimeImmutable $hoje): DespachosDaMetaOutput
    {
        $hoje = $hoje->setTime(0, 0);
        $podeCertificar = $this->quemCuida->ehResponsavelOuCriador($meta, $quemVe);
        $despachos = $meta->getMensagens()->toArray();
        $juntados = $this->juntados($meta, $escritorio, $despachos);

        $linhas = [];
        foreach ($despachos as $despacho) {
            $linhas[(int) $despacho->getId()] = $this->linha($despacho, $escritorio, $hoje, $podeCertificar, $juntados[(int) $despacho->getDocumentoJuntadoId()] ?? null);
        }

        return new DespachosDaMetaOutput($this->formulario($escritorio, $hoje), $linhas);
    }

    private function formulario(Tenant $escritorio, \DateTimeImmutable $hoje): FormularioDoDespachoOutput
    {
        $uf = $escritorio->getEstado();

        return new FormularioDoDespachoOutput(
            hoje: $hoje->format('Y-m-d'),
            prazoPadrao: $this->diasUteis->somarUteis($hoje, self::UTEIS_DO_PRAZO_PADRAO, $uf)->format('Y-m-d'),
            uteisDoPrazoPadrao: self::UTEIS_DO_PRAZO_PADRAO,
            naoUteis: $this->diasUteis->naoUteisEntre($hoje, $hoje->modify('+' . self::JANELA_EM_DIAS . ' days'), $uf),
            naturezas: array_map(
                static fn (NaturezaDoDespacho $n): array => ['valor' => $n->value, 'rotulo' => $n->rotulo(), 'inicio' => $n->inicioDoTexto()],
                NaturezaDoDespacho::cases(),
            ),
        );
    }

    /**
     * Os documentos juntados pelos despachos desta meta, por id (C4): só os do escritório e da pasta da meta.
     *
     * @param list<TarefaMensagem> $despachos
     *
     * @return array<int, PastaDocumento>
     */
    private function juntados(Tarefa $meta, Tenant $escritorio, array $despachos): array
    {
        $ids = array_values(array_filter(array_map(static fn (TarefaMensagem $d): ?int => $d->getDocumentoJuntadoId(), $despachos)));
        if ($ids === []) {
            return [];
        }
        $porId = [];
        foreach ($this->documentos->findBy(['id' => $ids, 'tenant' => $escritorio, 'pasta' => $meta->getPasta()]) as $documento) {
            $porId[(int) $documento->getId()] = $documento;
        }

        return $porId;
    }

    private function linha(TarefaMensagem $d, Tenant $escritorio, \DateTimeImmutable $hoje, bool $podeCertificar, ?PastaDocumento $juntado): DespachoNaLinhaDoTempoOutput
    {
        $prazo = $d->temCertidao() ? null : $d->getPrazoCumprimento();
        $cidade = trim((string) $escritorio->getCidade());

        return new DespachoNaLinhaDoTempoOutput(
            natureza: $d->getNatureza()?->rotulo(),
            tipo: $d->getNatureza()?->rotulo() ?? ($d->getAcao() ?? AcaoDoDespacho::Despacho)->rotulo(),
            acao: $d->getAcao()?->value,
            codigo: $d->getCodigo(),
            autor: (string) ($d->getUsuario()?->getFullName() ?? $d->getUsuario()?->getEmail()),
            registradoPor: $d->getRegistradoPorNome(),
            cargo: $d->getRegistradoPorCargo(),
            registradoEm: $d->getCriadoEm(),
            cidade: $cidade === '' ? null : $cidade,
            prazo: $prazo,
            prazoVencido: $prazo !== null && $prazo < $hoje,
            ciente: self::primeiroNome($d->getCientePor()),
            cienteEm: $d->getCienteEm(),
            certidao: $d->getCertidao(),
            certificadoPor: $d->getCertificadoPor()?->getFullName(),
            certificadoEm: $d->getCertificadoEm(),
            podeCertificar: $podeCertificar && $d->getAcao() === AcaoDoDespacho::Despacho && !$d->temCertidao(),
            para: self::mencionados($d->getMensagem()),
            anexoNome: $juntado?->getNomeOriginal(),
            anexoDocumentoId: $juntado?->getId(),
        );
    }

    /**
     * Os mencionados do texto (C3), na ordem e sem repetir, pelo nome curto do desenho (L161: as duas primeiras
     * palavras, com a inicial maiúscula). O rótulo do token é o nome do banco, gravado no envio.
     *
     * @return list<string>
     */
    private static function mencionados(string $texto): array
    {
        if (preg_match_all(MencoesDoRegistro::PADRAO, $texto, $achados, \PREG_SET_ORDER) === false) {
            return [];
        }
        $nomes = [];
        foreach ($achados as $achado) {
            $palavras = preg_split('/\s+/u', mb_convert_case(html_entity_decode($achado[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), MB_CASE_TITLE), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            $nomes[(int) $achado[2]] = implode(' ', array_slice($palavras, 0, 2));
        }

        return array_values($nomes);
    }

    private static function primeiroNome(?User $pessoa): ?string
    {
        if ($pessoa === null) {
            return null;
        }
        $nome = trim((string) ($pessoa->getFullName() ?? $pessoa->getEmail()));

        return explode(' ', $nome)[0];
    }
}
