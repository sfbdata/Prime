<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Enum\TipoJustificativa;

/**
 * O `detalhe` dos eventos da trilha do Ponto (E9): monta o que cada evento grava (ids, datas em ISO, o nome do
 * colaborador no momento) e escreve o texto da coluna "Detalhe" da aba Auditoria a partir dele.
 *
 * O detalhe gravado é selado: mudar o que se monta aqui vale só para os eventos novos. O texto, não: é lido de novo a
 * cada vez e pode mudar à vontade, inclusive para eventos antigos. Nada de observação livre, anexo ou coordenada no
 * detalhe: a trilha diz quem fez o quê, quando e de quem.
 *
 * O texto segue o do desenho 16 (dc L544, L601, L768), com as partes separadas por " · ": a batida é "Entrada às
 * 08:01:12", com " · trabalho externo" (E12) quando foi fora do raio e " · sem internet" quando veio da fila; a
 * justificativa é "dd/mm/aaaa · Tipo · Saída às 18:00". Ficam,
 * como extras necessários, o nome do colaborador na frente (o autor da linha nem sempre é ele), a data da batida (a
 * da fila e a do esquecimento não são do dia do evento), o " · home office" (a marca do lugar, como o " · trabalho
 * externo" do desenho) e o período do lote ("dd/mm/aaaa a dd/mm/aaaa (N dias)", ou "dd/mm/aaaa (N justificativas)"
 * num dia só), que grava um evento só para o envio inteiro.
 */
final class DetalheDaTrilhaDoPonto
{
    private const BATIDAS = ['entrada' => 'Entrada', 'repouso' => 'Repouso', 'retorno' => 'Retorno', 'saida' => 'Saída'];

    private const SITUACOES = ['pendente' => 'em análise', 'abonado' => 'abonada', 'rejeitado' => 'recusada'];

    /** A chave que separa o "Abonar selecionadas" da aba Equipe ({@see daEquipe}) do "aprovar todos" da ficha. */
    private const DA_EQUIPE = 'da_equipe';

    /** @return array<string, mixed> */
    public static function daBatida(RegistroPonto $batida): array
    {
        return self::colaborador($batida->getUser()) + [
            'tipo'             => $batida->getTipo(),
            'data_hora'        => $batida->getDataHora()?->format('Y-m-d H:i:s'),
            'home_office'      => $batida->isHomeOffice(),
            'sede'             => $batida->getSedeNomeSnapshot(),
            'trabalho_externo' => $batida->isTrabalhoExterno(),   // E12
            'sem_internet'     => $batida->isEnviadaSemInternet(),
        ];
    }

    /**
     * Um ajuste de batida (E10): o colaborador, a linhagem (o ajuste, o alvo, o que ele desfaz, a justificativa) e a
     * marcação antes e depois, cada uma com tipo e data/hora, ou nula onde não há (a inclusão não tem anterior, a
     * desconsideração não tem nova). Sem o motivo, que é texto livre.
     *
     * @return array<string, mixed>
     */
    public static function daAjuste(PontoAjuste $ajuste): array
    {
        return self::colaborador($ajuste->getUser()) + [
            'ajuste_id'        => $ajuste->getId(),
            'alvo'             => $ajuste->alvo(),
            'anterior'         => self::marcacao($ajuste->getTipoMarcacaoAnterior(), $ajuste->getDataHoraAnterior()),
            'novo'             => self::marcacao($ajuste->getTipoMarcacaoNova(), $ajuste->getDataHoraNova()),
            'justificativa_id' => $ajuste->getJustificativa()?->getId(),
            'desfaz_id'        => $ajuste->getDesfaz()?->getId(),
        ];
    }

    /**
     * A inclusão que o abono de um esquecimento criou (E10): o evento continua `batida_do_esquecimento`, com o detalhe do
     * ajuste e, como a batida de antes da E10, o tipo e a data/hora no primeiro nível (o texto da trilha não muda).
     *
     * @return array<string, mixed>
     */
    public static function daInclusaoDoEsquecimento(PontoAjuste $inclusao): array
    {
        if ($inclusao->getTipo() !== TipoDoAjuste::Inclusao || $inclusao->getJustificativa() === null) {
            throw new \LogicException('O evento do esquecimento abonado é de uma inclusão ligada à justificativa.');
        }

        return self::daAjuste($inclusao) + [
            'tipo'      => $inclusao->getTipoMarcacaoNova(),
            'data_hora' => $inclusao->getDataHoraNova()?->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, mixed> */
    public static function daJustificativa(JustificativaPonto $justificativa): array
    {
        return self::colaborador($justificativa->getUser()) + [
            'justificativa_id' => $justificativa->getId(),
            'tipo'             => $justificativa->getTipo(),
            'data'             => $justificativa->getData()?->format('Y-m-d'),
        ] + self::esquecimento($justificativa) + self::parcial($justificativa);
    }

    /**
     * Um lote (as justificativas de um envio, de um lançamento ou de um abono em lote), num evento só.
     *
     * @param list<JustificativaPonto> $lote
     *
     * @return array<string, mixed>
     */
    public static function doLote(array $lote): array
    {
        if ($lote === []) {
            throw new \LogicException('Evento de lote sem nenhuma justificativa.');
        }
        $datas = array_map(static fn (JustificativaPonto $j): string => (string) $j->getData()?->format('Y-m-d'), $lote);
        sort($datas);

        return self::colaborador($lote[0]->getUser()) + [
            'justificativa_ids' => array_map(static fn (JustificativaPonto $j): ?int => $j->getId(), $lote),
            'tipo'              => $lote[0]->getTipo(),
            'de'                => $datas[0],
            'ate'               => $datas[\count($datas) - 1],
            'quantidade'        => \count($lote),
        ] + self::esquecimento($lote[0]) + self::parcial($lote[0]);
    }

    /**
     * O "Abonar selecionadas" da aba Equipe (E5, desenho L818): as justificativas da equipe abonadas de uma vez, de
     * várias pessoas, num evento "Abono em lote" só. Guarda de cada uma o id, a pessoa e o dia, para a auditoria saber
     * quem foi abonado; a tela mostra só a quantidade, como o desenho. A chave `da_equipe` separa este detalhe do
     * "aprovar todos" da ficha ({@see doLote}, de uma pessoa só), que tem o texto dele.
     *
     * @param list<JustificativaPonto> $abonadas
     *
     * @return array<string, mixed>
     */
    public static function daEquipe(array $abonadas): array
    {
        if ($abonadas === []) {
            throw new \LogicException('Evento de lote sem nenhuma justificativa.');
        }

        return [
            self::DA_EQUIPE => array_map(static fn (JustificativaPonto $j): array => [
                'justificativa_id' => $j->getId(),
            ] + self::colaborador($j->getUser()) + [
                'data' => $j->getData()?->format('Y-m-d'),
            ], $abonadas),
            'quantidade' => \count($abonadas),
        ];
    }

    /**
     * A troca de escala (versão da jornada salva ou excluída, escala pessoal limpa). A troca da aba Equipe leva o
     * rótulo da escala do catálogo em `escala` (desenho L778); a ficha, sem rótulo, fica sem a chave.
     *
     * @param 'versao_salva'|'versao_excluida'|'escala_limpa' $operacao
     *
     * @return array<string, mixed>
     */
    public static function daEscala(User $pessoa, string $operacao, ?\DateTimeInterface $valeAPartirDe = null, ?string $escala = null): array
    {
        return self::colaborador($pessoa) + [
            'operacao'          => $operacao,
            'vale_a_partir_de'  => $valeAPartirDe?->format('Y-m-d'),
        ] + ($escala === null ? [] : ['escala' => $escala]);
    }

    /**
     * A chave "Escritório" de uma função do Ponto (E7), ligada ou desligada pelo administrador do ponto. O título é o da
     * função no momento, como a linha da aba Configurações o mostra.
     *
     * @return array{chave: string, titulo: string, ligada: bool}
     */
    public static function daConfiguracao(string $chave, string $titulo, bool $ligada): array
    {
        return ['chave' => $chave, 'titulo' => $titulo, 'ligada' => $ligada];
    }

    /**
     * A rodada da fila sem internet que enviou batidas (E14, dc L438): quem, quais batidas e quantas.
     *
     * @param list<int> $registroIds
     *
     * @return array<string, mixed>
     */
    public static function daSincronizacao(User $pessoa, array $registroIds): array
    {
        return self::colaborador($pessoa) + ['registro_ids' => $registroIds, 'quantidade' => \count($registroIds)];
    }

    /**
     * A folha emitida com código BJF (E16, decisão 18): a pessoa da folha, a competência e o código. Quem emitiu (a
     * própria pessoa ou o gestor) é o autor do evento.
     *
     * @return array<string, mixed>
     */
    public static function daFolhaEmitida(FolhaEmitidaDoPonto $folha): array
    {
        return self::colaborador($folha->getUser()) + [
            'folha_emitida_id' => $folha->getId(),
            'ano'              => $folha->getAno(),
            'mes'              => $folha->getMes(),
            'codigo'           => $folha->getCodigo(),
        ];
    }

    /**
     * O aceite da folha (E16, decisões 1 e 14): a pessoa, a competência, o número do aceite e o código da folha vista.
     * Sem o texto da ressalva, que fica só no aceite selado.
     *
     * @return array<string, mixed>
     */
    public static function doAceiteDaFolha(AceiteDaFolhaDoPonto $aceite): array
    {
        return self::colaborador($aceite->getUser()) + [
            'aceite_id' => $aceite->getId(),
            'ano'       => $aceite->getAno(),
            'mes'       => $aceite->getMes(),
            'numero'    => $aceite->getNumero(),
            'codigo'    => $aceite->getFolhaVista()->getCodigo(),
        ];
    }

    /** @param array<string, mixed> $detalhe */
    public static function texto(string $acao, array $detalhe): string
    {
        $evento = AcaoDaTrilhaDoPonto::tryFrom($acao);
        if ($evento === AcaoDaTrilhaDoPonto::ConfiguracaoDoEscritorio) {
            return self::textoDaConfiguracao($detalhe);
        }
        if ($evento === AcaoDaTrilhaDoPonto::AbonoEmLote && \is_array($detalhe[self::DA_EQUIPE] ?? null)) {
            return self::textoDaEquipe($detalhe);   // várias pessoas: sem o "Nome: " na frente (desenho L818)
        }
        $quem = self::textoDe($detalhe, 'colaborador') ?? 'Colaborador';
        $escala = $evento === AcaoDaTrilhaDoPonto::EscalaAlterada ? self::textoDe($detalhe, 'escala') : null;
        if ($escala !== null) {
            return $quem . ' · ' . $escala;   // a troca da aba Equipe, como o desenho (L778): "Ana · 44h · seg a sáb (7h20)"
        }

        return $quem . ': ' . match ($evento) {
            AcaoDaTrilhaDoPonto::BatidaRegistrada     => self::textoDaBatida($detalhe),
            AcaoDaTrilhaDoPonto::BatidaDoEsquecimento => self::textoDaBatida($detalhe) . ' · pedida no esquecimento',
            AcaoDaTrilhaDoPonto::JustificativaEnviada,
            AcaoDaTrilhaDoPonto::JustificativaLancada,
            AcaoDaTrilhaDoPonto::AbonoEmLote          => self::textoDoLote($detalhe),
            AcaoDaTrilhaDoPonto::JustificativaReaberta => self::textoDaJustificativa($detalhe) . self::textoDaSituacaoAnterior($detalhe),
            AcaoDaTrilhaDoPonto::EscalaAlterada       => self::textoDaEscala($detalhe),
            AcaoDaTrilhaDoPonto::Sincronizacao        => self::textoDaSincronizacao($detalhe),
            AcaoDaTrilhaDoPonto::BatidaIncluida       => self::textoDaMarcacao($detalhe, 'novo') . ' incluída',
            AcaoDaTrilhaDoPonto::BatidaAjustada       => self::textoDaMarcacao($detalhe, 'anterior') . ' passou para ' . self::textoDaMarcacao($detalhe, 'novo'),
            AcaoDaTrilhaDoPonto::BatidaDesconsiderada => self::textoDaMarcacao($detalhe, 'anterior') . ' desconsiderada',
            AcaoDaTrilhaDoPonto::AjusteDesfeito       => self::textoDoAjusteDesfeito($detalhe),
            AcaoDaTrilhaDoPonto::FolhaEmitida         => self::textoDaFolhaEmitida($detalhe),
            AcaoDaTrilhaDoPonto::FolhaAssinada,
            AcaoDaTrilhaDoPonto::FolhaAssinadaComRessalva => self::textoDaCompetencia($detalhe),
            default                                   => self::textoDaJustificativa($detalhe),
        };
    }

    /** @return array{colaborador_id: ?int, colaborador: ?string} */
    private static function colaborador(?User $pessoa): array
    {
        $nome = $pessoa === null ? null : trim((string) $pessoa->getFullName());

        return [
            'colaborador_id' => $pessoa?->getId(),
            'colaborador'    => $nome !== null && $nome !== '' ? $nome : $pessoa?->getEmail(),
        ];
    }

    /** @return array<string, string> */
    private static function esquecimento(JustificativaPonto $justificativa): array
    {
        $tipo = $justificativa->getTipoRegistroEsquecido();
        $hora = $justificativa->getHoraRegistroEsquecido();
        if ($tipo === null || $tipo === '' || $hora === null) {
            return [];
        }

        return ['batida_esquecida' => $tipo, 'hora_esquecida' => $hora->format('H:i')];
    }

    /**
     * O abono parcial (só o horário, como o subtítulo da lista): `parcial` e, quando há, `parcial_de` e `parcial_ate`.
     *
     * @return array<string, bool|string>
     */
    private static function parcial(JustificativaPonto $justificativa): array
    {
        if (!$justificativa->isAbonoParcial()) {
            return [];
        }
        $de  = $justificativa->getHoraInicioAbono();
        $ate = $justificativa->getHoraFimAbono();

        return ['parcial' => true] + ($de !== null && $ate !== null ? ['parcial_de' => $de->format('H:i'), 'parcial_ate' => $ate->format('H:i')] : []);
    }

    /** @param array<string, mixed> $detalhe */
    private static function textoDaBatida(array $detalhe): string
    {
        $tipo    = self::BATIDAS[self::textoDe($detalhe, 'tipo') ?? ''] ?? (self::textoDe($detalhe, 'tipo') ?? 'Batida');
        $momento = self::momento(self::textoDe($detalhe, 'data_hora'), 'H:i:s \d\e d/m/Y');
        $marcas  = (($detalhe['home_office'] ?? false) === true ? ' · home office' : '')
            . (($detalhe['trabalho_externo'] ?? false) === true ? ' · trabalho externo' : '')
            . (($detalhe['sem_internet'] ?? false) === true ? ' · sem internet' : '');

        return $tipo . ($momento !== null ? ' às ' . $momento : '') . $marcas;
    }

    /**
     * Um lado do ajuste ('anterior' ou 'novo') no texto da batida: "Saída às 18:00:00 de 21/10/2026".
     *
     * @param array<string, mixed> $detalhe
     */
    private static function textoDaMarcacao(array $detalhe, string $lado): string
    {
        $marcacao = $detalhe[$lado] ?? null;

        /** @var array<string, mixed> $marcacao */
        $marcacao = is_array($marcacao) ? $marcacao : [];

        return self::textoDaBatida($marcacao);
    }

    /**
     * A restauração traz a batida de volta (tem a nova); sem a nova, é a inclusão do esquecimento desconsiderada quando a
     * justificativa voltou para análise.
     *
     * @param array<string, mixed> $detalhe
     */
    private static function textoDoAjusteDesfeito(array $detalhe): string
    {
        if (is_array($detalhe['novo'] ?? null)) {
            return self::textoDaMarcacao($detalhe, 'novo') . ' restaurada';
        }

        return self::textoDaMarcacao($detalhe, 'anterior') . ', do esquecimento abonado, desconsiderada ao voltar a justificativa para análise';
    }

    /** @return array{tipo: string, data_hora: string}|null */
    private static function marcacao(?string $tipo, ?\DateTimeImmutable $dataHora): ?array
    {
        if ($tipo === null || $dataHora === null) {
            return null;
        }

        return ['tipo' => $tipo, 'data_hora' => $dataHora->format('Y-m-d H:i:s')];
    }

    /** @param array<string, mixed> $detalhe */
    private static function textoDaJustificativa(array $detalhe): string
    {
        $data = self::momento(self::textoDe($detalhe, 'data'), 'd/m/Y');

        return self::partes([$data, self::tipoDaJustificativa($detalhe), self::subtitulo($detalhe)]);
    }

    /** @param array<string, mixed> $detalhe */
    private static function textoDoLote(array $detalhe): string
    {
        $de         = self::momento(self::textoDe($detalhe, 'de'), 'd/m/Y');
        $ate        = self::momento(self::textoDe($detalhe, 'ate'), 'd/m/Y');
        $quantidade = (int) ($detalhe['quantidade'] ?? 0);
        $periodo    = match (true) {
            $de === null || $ate === null => $de,
            $de !== $ate                  => sprintf('%s a %s', $de, $ate) . ($quantidade > 1 ? sprintf(' (%d dias)', $quantidade) : ''),
            default                       => $de . ($quantidade > 1 ? sprintf(' (%d justificativas)', $quantidade) : ''),
        };

        return self::partes([$periodo, self::tipoDaJustificativa($detalhe), self::subtitulo($detalhe)]);
    }

    /**
     * "1 justificativa da equipe" ou "N justificativas da equipe" (desenho L818, no plural certo).
     *
     * @param array<string, mixed> $detalhe
     */
    private static function textoDaEquipe(array $detalhe): string
    {
        $quantidade = \is_int($detalhe['quantidade'] ?? null) ? $detalhe['quantidade'] : \count((array) $detalhe[self::DA_EQUIPE]);

        return $quantidade === 1 ? '1 justificativa da equipe' : sprintf('%d justificativas da equipe', $quantidade);
    }

    /**
     * Como o desenho (dc L761): "Geolocalização desativado", sem o nome de um colaborador na frente (a chave é do
     * escritório; quem mudou está na coluna "Autor").
     *
     * @param array<string, mixed> $detalhe
     */
    private static function textoDaConfiguracao(array $detalhe): string
    {
        $titulo = self::textoDe($detalhe, 'titulo') ?? self::textoDe($detalhe, 'chave') ?? 'Função';

        return $titulo . (($detalhe['ligada'] ?? null) === true ? ' ativado' : ' desativado');
    }

    /**
     * Como o desenho (dc L438), "N batida(s) enviada(s) ao voltar a internet", com o plural escrito por inteiro, como as
     * mensagens do abono em lote da E5: "1 batida enviada" e "3 batidas enviadas".
     *
     * @param array<string, mixed> $detalhe
     */
    private static function textoDaSincronizacao(array $detalhe): string
    {
        $quantidade = (int) ($detalhe['quantidade'] ?? 0);

        return sprintf($quantidade === 1 ? '%d batida enviada ao voltar a internet' : '%d batidas enviadas ao voltar a internet', $quantidade);
    }

    /** @param array<string, mixed> $detalhe */
    private static function textoDaEscala(array $detalhe): string
    {
        $data = self::momento(self::textoDe($detalhe, 'vale_a_partir_de'), 'd/m/Y');

        return match (self::textoDe($detalhe, 'operacao')) {
            'versao_salva'    => 'versão da jornada a partir de ' . ($data ?? 'data não informada') . ' salva',
            'versao_excluida' => 'versão da jornada de ' . ($data ?? 'data não informada') . ' excluída',
            'escala_limpa'    => 'jornada personalizada limpa (volta a do escritório)',
            default           => 'escala alterada',
        };
    }

    /**
     * "11/2026 · BJF-ABCD-EFGH-JK23", como o desenho (L727: a folha e o código).
     *
     * @param array<string, mixed> $detalhe
     */
    private static function textoDaFolhaEmitida(array $detalhe): string
    {
        $codigo = self::textoDe($detalhe, 'codigo');

        return self::textoDaCompetencia($detalhe) . ($codigo !== null ? ' · ' . $codigo : '');
    }

    /**
     * "11/2026", a competência da folha (desenho L727 e L809).
     *
     * @param array<string, mixed> $detalhe
     */
    private static function textoDaCompetencia(array $detalhe): string
    {
        $ano = $detalhe['ano'] ?? null;
        $mes = $detalhe['mes'] ?? null;

        return is_int($ano) && is_int($mes) ? sprintf('%02d/%04d', $mes, $ano) : 'competência não informada';
    }

    /** @param array<string, mixed> $detalhe */
    private static function textoDaSituacaoAnterior(array $detalhe): string
    {
        $anterior = self::SITUACOES[self::textoDe($detalhe, 'situacao_anterior') ?? ''] ?? null;

        return $anterior === null ? '' : ' · estava ' . $anterior;
    }

    /**
     * O subtítulo, como o da lista (`_justificativa_macros.html.twig`): o do esquecimento, "Saída às 18:00", ou o do
     * abono parcial, "Parcial: 14:00 às 16:00" (só "Parcial" sem o horário). Eventos gravados antes do parcial no
     * detalhe saem sem ele.
     *
     * @param array<string, mixed> $detalhe
     */
    private static function subtitulo(array $detalhe): ?string
    {
        $batida = self::BATIDAS[self::textoDe($detalhe, 'batida_esquecida') ?? ''] ?? null;
        $hora   = self::textoDe($detalhe, 'hora_esquecida');
        if ($batida !== null && $hora !== null) {
            return sprintf('%s às %s', $batida, $hora);
        }
        if (($detalhe['parcial'] ?? false) !== true) {
            return null;
        }
        $de  = self::textoDe($detalhe, 'parcial_de');
        $ate = self::textoDe($detalhe, 'parcial_ate');

        return $de === null || $ate === null ? 'Parcial' : sprintf('Parcial: %s às %s', $de, $ate);
    }

    /**
     * Junta as partes que vieram com " · ", como o desenho (dc L601, L768).
     *
     * @param list<?string> $partes
     */
    private static function partes(array $partes): string
    {
        return implode(' · ', array_filter($partes, static fn (?string $parte): bool => $parte !== null && $parte !== ''));
    }

    /** @param array<string, mixed> $detalhe */
    private static function tipoDaJustificativa(array $detalhe): string
    {
        $tipo = self::textoDe($detalhe, 'tipo') ?? '';

        return TipoJustificativa::tryFrom($tipo)?->label() ?? ($tipo !== '' ? $tipo : 'Justificativa');
    }

    private static function momento(?string $iso, string $formato): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->format($formato);
        } catch (\Exception) {
            return $iso;
        }
    }

    /** @param array<string, mixed> $detalhe */
    private static function textoDe(array $detalhe, string $campo): ?string
    {
        $valor = $detalhe[$campo] ?? null;

        return is_scalar($valor) && (string) $valor !== '' ? (string) $valor : null;
    }
}
