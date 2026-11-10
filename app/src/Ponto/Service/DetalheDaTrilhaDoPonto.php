<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
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
 * 08:01:12", com " · sem internet" quando veio da fila; a justificativa é "dd/mm/aaaa · Tipo · Saída às 18:00". Ficam,
 * como extras necessários, o nome do colaborador na frente (o autor da linha nem sempre é ele), a data da batida (a
 * da fila e a do esquecimento não são do dia do evento), o " · home office" (a marca do lugar, como o " · trabalho
 * externo" do desenho) e o período do lote ("de a até (N dias)"), que grava um evento só para o envio inteiro.
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
     * A batida que o abono de um esquecimento criou: a da análise (`RegistrarBatidaDoEsquecimentoUseCase`) e a do
     * lançamento já abonado pelo gestor (`TenantController::novaJustificativaAdmin`) gravam o mesmo detalhe.
     *
     * @return array<string, mixed>
     */
    public static function daBatidaDoEsquecimento(RegistroPonto $batida, JustificativaPonto $justificativa): array
    {
        return self::daBatida($batida) + ['justificativa_id' => $justificativa->getId()];
    }

    /** @return array<string, mixed> */
    public static function daJustificativa(JustificativaPonto $justificativa): array
    {
        return self::colaborador($justificativa->getUser()) + [
            'justificativa_id' => $justificativa->getId(),
            'tipo'             => $justificativa->getTipo(),
            'data'             => $justificativa->getData()?->format('Y-m-d'),
        ] + self::esquecimento($justificativa);
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
        ] + self::esquecimento($lote[0]);
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
     * A troca de escala (versão da jornada salva ou excluída, escala pessoal limpa).
     *
     * @param 'versao_salva'|'versao_excluida'|'escala_limpa' $operacao
     *
     * @return array<string, mixed>
     */
    public static function daEscala(User $pessoa, string $operacao, ?\DateTimeInterface $valeAPartirDe = null): array
    {
        return self::colaborador($pessoa) + [
            'operacao'          => $operacao,
            'vale_a_partir_de'  => $valeAPartirDe?->format('Y-m-d'),
        ];
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

        return $quem . ': ' . match ($evento) {
            AcaoDaTrilhaDoPonto::BatidaRegistrada     => self::textoDaBatida($detalhe),
            AcaoDaTrilhaDoPonto::BatidaDoEsquecimento => self::textoDaBatida($detalhe) . ' · pedida no esquecimento',
            AcaoDaTrilhaDoPonto::JustificativaEnviada,
            AcaoDaTrilhaDoPonto::JustificativaLancada,
            AcaoDaTrilhaDoPonto::AbonoEmLote          => self::textoDoLote($detalhe),
            AcaoDaTrilhaDoPonto::JustificativaReaberta => self::textoDaJustificativa($detalhe) . self::textoDaSituacaoAnterior($detalhe),
            AcaoDaTrilhaDoPonto::EscalaAlterada       => self::textoDaEscala($detalhe),
            AcaoDaTrilhaDoPonto::Sincronizacao        => self::textoDaSincronizacao($detalhe),
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

    /** @param array<string, mixed> $detalhe */
    private static function textoDaBatida(array $detalhe): string
    {
        $tipo    = self::BATIDAS[self::textoDe($detalhe, 'tipo') ?? ''] ?? (self::textoDe($detalhe, 'tipo') ?? 'Batida');
        $momento = self::momento(self::textoDe($detalhe, 'data_hora'), 'H:i:s \d\e d/m/Y');
        $marcas  = (($detalhe['home_office'] ?? false) === true ? ' · home office' : '')
            . (($detalhe['sem_internet'] ?? false) === true ? ' · sem internet' : '');

        return $tipo . ($momento !== null ? ' às ' . $momento : '') . $marcas;
    }

    /** @param array<string, mixed> $detalhe */
    private static function textoDaJustificativa(array $detalhe): string
    {
        $data = self::momento(self::textoDe($detalhe, 'data'), 'd/m/Y');

        return self::partes([$data, self::tipoDaJustificativa($detalhe), self::textoDoEsquecimento($detalhe)]);
    }

    /** @param array<string, mixed> $detalhe */
    private static function textoDoLote(array $detalhe): string
    {
        $de         = self::momento(self::textoDe($detalhe, 'de'), 'd/m/Y');
        $ate        = self::momento(self::textoDe($detalhe, 'ate'), 'd/m/Y');
        $quantidade = (int) ($detalhe['quantidade'] ?? 0);
        $periodo    = $de === null || $ate === null || $de === $ate ? $de : sprintf('%s a %s', $de, $ate) . ($quantidade > 1 ? sprintf(' (%d dias)', $quantidade) : '');

        return self::partes([$periodo, self::tipoDaJustificativa($detalhe), self::textoDoEsquecimento($detalhe)]);
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

    /** @param array<string, mixed> $detalhe */
    private static function textoDaSituacaoAnterior(array $detalhe): string
    {
        $anterior = self::SITUACOES[self::textoDe($detalhe, 'situacao_anterior') ?? ''] ?? null;

        return $anterior === null ? '' : ' · estava ' . $anterior;
    }

    /**
     * O subtítulo do esquecimento, como o da lista (`_justificativa_macros.html.twig`): "Saída às 18:00".
     *
     * @param array<string, mixed> $detalhe
     */
    private static function textoDoEsquecimento(array $detalhe): ?string
    {
        $batida = self::BATIDAS[self::textoDe($detalhe, 'batida_esquecida') ?? ''] ?? null;
        $hora   = self::textoDe($detalhe, 'hora_esquecida');

        return $batida === null || $hora === null ? null : sprintf('%s às %s', $batida, $hora);
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
