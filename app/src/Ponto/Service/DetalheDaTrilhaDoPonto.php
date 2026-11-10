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
 */
final class DetalheDaTrilhaDoPonto
{
    private const BATIDAS = ['entrada' => 'Entrada', 'repouso' => 'Repouso', 'retorno' => 'Retorno', 'saida' => 'Saída'];

    private const SITUACOES = ['pendente' => 'em análise', 'abonado' => 'abonada', 'rejeitado' => 'recusada'];

    /** @return array<string, mixed> */
    public static function daBatida(RegistroPonto $batida): array
    {
        return self::colaborador($batida->getUser()) + [
            'tipo'        => $batida->getTipo(),
            'data_hora'   => $batida->getDataHora()?->format('Y-m-d H:i:s'),
            'home_office' => $batida->isHomeOffice(),
            'sede'        => $batida->getSedeNomeSnapshot(),
            'trabalho_externo' => $batida->isTrabalhoExterno(),   // E12
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

    /** @param array<string, mixed> $detalhe */
    public static function texto(string $acao, array $detalhe): string
    {
        $quem = self::textoDe($detalhe, 'colaborador') ?? 'Colaborador';

        return $quem . ': ' . match (AcaoDaTrilhaDoPonto::tryFrom($acao)) {
            AcaoDaTrilhaDoPonto::BatidaRegistrada     => self::textoDaBatida($detalhe),
            AcaoDaTrilhaDoPonto::BatidaDoEsquecimento => self::textoDaBatida($detalhe) . ', pedida no esquecimento',
            AcaoDaTrilhaDoPonto::JustificativaEnviada,
            AcaoDaTrilhaDoPonto::JustificativaLancada,
            AcaoDaTrilhaDoPonto::AbonoEmLote          => self::textoDoLote($detalhe),
            AcaoDaTrilhaDoPonto::JustificativaReaberta => self::textoDaJustificativa($detalhe) . self::textoDaSituacaoAnterior($detalhe),
            AcaoDaTrilhaDoPonto::EscalaAlterada       => self::textoDaEscala($detalhe),
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
        $tipo     = self::BATIDAS[self::textoDe($detalhe, 'tipo') ?? ''] ?? (self::textoDe($detalhe, 'tipo') ?? 'Batida');
        $momento  = self::momento(self::textoDe($detalhe, 'data_hora'), 'H:i \d\e d/m/Y');
        $onde     = ($detalhe['home_office'] ?? false) === true ? ', em home office' : '';
        $sede     = self::textoDe($detalhe, 'sede');
        $onde     = $onde === '' && $sede !== null ? ', na sede ' . $sede : $onde;

        return $tipo . ($momento !== null ? ' às ' . $momento : '') . $onde;
    }

    /** @param array<string, mixed> $detalhe */
    private static function textoDaJustificativa(array $detalhe): string
    {
        $data = self::momento(self::textoDe($detalhe, 'data'), 'd/m/Y');

        return self::tipoDaJustificativa($detalhe) . ($data !== null ? ' de ' . $data : '') . self::textoDoEsquecimento($detalhe);
    }

    /** @param array<string, mixed> $detalhe */
    private static function textoDoLote(array $detalhe): string
    {
        $de         = self::momento(self::textoDe($detalhe, 'de'), 'd/m/Y');
        $ate        = self::momento(self::textoDe($detalhe, 'ate'), 'd/m/Y');
        $quantidade = (int) ($detalhe['quantidade'] ?? 0);
        $periodo    = $de === null ? '' : ($de === $ate || $ate === null ? ' de ' . $de : ' de ' . $de . ' a ' . $ate);
        $dias       = $quantidade > 1 ? sprintf(' (%d dias)', $quantidade) : '';

        return self::tipoDaJustificativa($detalhe) . $periodo . $dias . self::textoDoEsquecimento($detalhe);
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

        return $anterior === null ? '' : ', estava ' . $anterior;
    }

    /** @param array<string, mixed> $detalhe */
    private static function textoDoEsquecimento(array $detalhe): string
    {
        $batida = self::BATIDAS[self::textoDe($detalhe, 'batida_esquecida') ?? ''] ?? null;
        $hora   = self::textoDe($detalhe, 'hora_esquecida');

        return $batida === null || $hora === null ? '' : sprintf(' (%s às %s)', $batida, $hora);
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
