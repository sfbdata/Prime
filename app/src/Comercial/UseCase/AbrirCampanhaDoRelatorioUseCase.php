<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Service\RastreioDaLeitura;
use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\DTO\CampanhaDoRelatorio;
use App\Comercial\DTO\DestinatarioDoRelatorio;
use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Entity\EnvioDeEmail;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\RelatorioDeCampanhasRepository;
use App\Shared\Email\IdentidadeDeEmail;

/**
 * O visualizador de uma campanha do Relatório (desenho 05 VS 21.10.26, L502-514 e L710-714; spec
 * `comercial-relatorio.md`): o cabeçalho, os 4 KPIs, o HTML remontado da cópia congelada (`conteudo`) pelo mesmo
 * montador do envio ({@see ComposicaoDaCampanha::previa()}, com o nome de exemplo no `{nome}`) e até 200
 * destinatários com o estado do envio. Null se a campanha não existe.
 *
 * "Cancelar envio" só onde o {@see EncerrarCampanhaUseCase} aceita: estado ativo (na fila, agendada, enviando,
 * recorrente) ou `enviada` com envio ainda na fila do Carteiro (freio aprovado pelo dono em 23/10).
 *
 * @phpstan-import-type Campanha from CampanhaDeEmailRepository
 */
final class AbrirCampanhaDoRelatorioUseCase
{
    public const ANONIMIZADO = 'endereço anonimizado';
    /** O estado de cada destinatário (o dc L713 escreve "enviado · aguardando leitura"). */
    public const ESTADOS = [
        EnvioDeEmail::ENFILEIRADO => 'na fila', EnvioDeEmail::ENVIANDO => 'na fila', EnvioDeEmail::ENVIADO => 'enviado · aguardando leitura',
        EnvioDeEmail::FALHOU => 'rejeitado', EnvioDeEmail::BLOQUEADO_DESCADASTRO => 'descadastrado', EnvioDeEmail::CANCELADO => 'cancelado',
        EnvioDeEmail::INCERTO => 'envio incerto', EnvioDeEmail::SIMULADO => 'simulado',
    ];
    public const LIDO = 'lido';

    public function __construct(
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly RelatorioDeCampanhasRepository $relatorio,
        private readonly ComposicaoDaCampanha $composicao,
        private readonly IdentidadeDeEmail $identidade,
        private readonly RastreioDaLeitura $rastreio,
    ) {
    }

    public function executar(int $id): ?CampanhaDoRelatorio
    {
        $c = $this->campanhas->porId($id);
        if ($c === null) {
            return null;
        }
        $metricas = ListarRelatorioDeCampanhasUseCase::metricas($c, $this->relatorio->metricas([$id])[$id] ?? null, $this->rastreio->efetivo($c['rastrear_leitura']));
        $destinatarios = array_map(self::destinatario(...), $this->relatorio->destinatarios($id, CampanhaDoRelatorio::LIMITE));

        return new CampanhaDoRelatorio(
            $id,
            $c['assunto'],
            $c['titulo'],
            $c['estado'],
            self::situacao($c['estado'], $c['frequencia']),
            $this->remetente(),
            ListarRelatorioDeCampanhasUseCase::rotuloDoPublico($c['publico'], $c['total']),
            $c['modelo_nome'],
            (new \DateTimeImmutable($c['agendada_para'] ?? $c['criada_em']))->format('d/m/Y H:i'),
            self::cancelavel($c['estado'], fn (): bool => $this->relatorio->temEnvioNaFila($id)),
            $c['execucoes'] > 0,
            $metricas,
            $this->html($c),
            $destinatarios,
            max(0, $metricas->destinatarios - \count($destinatarios)),
        );
    }

    /**
     * O que o EncerrarCampanhaUseCase aceita (§8.1.5): estado ativo, ou `enviada` com envio na fila.
     *
     * @param \Closure(): bool $temFila só consultada na `enviada`
     */
    public static function cancelavel(string $estado, \Closure $temFila): bool
    {
        if (\in_array($estado, CampanhaDeEmail::ATIVAS, true)) {
            return true;
        }

        return $estado === CampanhaDeEmail::ENVIADA && $temFila();
    }

    /** O estado ao lado do assunto (dc L713: "E-mail de teste", "Recorrente · freq", "Agendada", "Na fila de envio"). */
    public static function situacao(string $estado, string $frequencia): string
    {
        return match ($estado) {
            ListarRelatorioDeCampanhasUseCase::TESTE => 'E-mail de teste',
            CampanhaDeEmail::RECORRENTE => 'Recorrente · ' . $frequencia,
            CampanhaDeEmail::NA_FILA => 'Na fila de envio',
            default => ListarRelatorioDeCampanhasUseCase::ROTULOS[$estado] ?? $estado,
        };
    }

    /** @param array{email: string, situacao: ?string, aberto: bool, clicou: bool} $d */
    private static function destinatario(array $d): DestinatarioDoRelatorio
    {
        $email = str_contains($d['email'], '@') ? $d['email'] : self::ANONIMIZADO;
        $estado = $d['aberto'] ? self::LIDO : (self::ESTADOS[$d['situacao'] ?? EnvioDeEmail::ENFILEIRADO] ?? (string) $d['situacao']);

        return new DestinatarioDoRelatorio($email, $estado, $d['aberto']);
    }

    /**
     * O HTML que o destinatário recebe, remontado da cópia congelada. Null se ela não passa mais na validação.
     *
     * @param Campanha $c
     */
    private function html(array $c): ?string
    {
        try {
            return $this->composicao->previa($this->composicao->descongelar($c));
        } catch (CampanhaRecusada|\InvalidArgumentException) {
            return null;
        }
    }

    /** O remetente vigente (nome e endereço configurados pelo Master, senão o do ambiente). */
    private function remetente(): string
    {
        return $this->identidade->remetente();
    }
}
