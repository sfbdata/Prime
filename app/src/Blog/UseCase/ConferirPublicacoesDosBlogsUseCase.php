<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Enum\SituacaoDaPublicacao;
use App\Blog\Repository\PublicacaoDosBlogsRepository;
use App\Blog\Service\PastaDosPedidos;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Lê o que o builder escreveu na pasta de cada pedido aberto (spec painel-artigos-blogs, seção 6.1, "O BlueJus lê o
 * resultado"). Roda a cada minuto pelo comando `blog:conferir-publicacoes`.
 *
 * Para cada pedido na fila ou gerando, o mais antigo primeiro:
 *  - `resultado.json` presente: grava situação (no_ar ou falhou), commit, log, início e fim, e apaga a pasta do pedido
 *    (depois do flush: se a gravação cair, a próxima rodada lê de novo);
 *  - `iniciado` presente: o pedido passa a gerando;
 *  - nada disso 30 minutos depois de `iniciado` (ou, se o builder nem começou, 30 minutos depois do pedido): falhou,
 *    e a pasta do pedido é apagada, para o builder não publicar depois um pedido dado como falho.
 *
 * Datas do builder em ISO 8601 viram o fuso do PHP (o banco guarda sem fuso). Resultado que não se entende (situação
 * fora de no_ar/falhou) conta como falha. O log perde as sequências de cor do terminal (ANSI), inclusive o `[32m` que
 * sobra quando o ESC se perde.
 *
 * Antes de tudo, apaga as montagens `.{id}.montando` paradas há mais de {@see self::MONTAGEM_ABANDONADA} (o worker caiu
 * no meio): o builder as ignora, mas elas ocupariam o volume para sempre.
 */
final class ConferirPublicacoesDosBlogsUseCase
{
    public const PRAZO_DO_BUILDER = '+30 minutes';
    public const MONTAGEM_ABANDONADA = '-1 hour';
    private const COR_ANSI = '/\x1b?\[[0-9;]*m/';

    public function __construct(
        private readonly PublicacaoDosBlogsRepository $publicacoes,
        private readonly PastaDosPedidos $pasta,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @return array{gerando: int, noAr: int, falhou: int} quantos pedidos mudaram para cada situação nesta rodada */
    public function executar(): array
    {
        $resumo = ['gerando' => 0, 'noAr' => 0, 'falhou' => 0];
        $this->pasta->apagarMontagensAbandonadas($this->relogio->now()->modify(self::MONTAGEM_ABANDONADA));
        foreach ($this->publicacoes->abertas() as $publicacao) {
            $antes = $publicacao->getSituacao();
            $apagar = $this->conferir($publicacao);
            if ($antes === $publicacao->getSituacao()) {
                continue;
            }
            $this->escopoDePlataforma->executar(fn () => $this->publicacoes->salvar($publicacao, flush: true));
            if ($apagar) {
                $this->pasta->remover((int) $publicacao->getId());
            }
            match ($publicacao->getSituacao()) {
                SituacaoDaPublicacao::Gerando => ++$resumo['gerando'],
                SituacaoDaPublicacao::NoAr => ++$resumo['noAr'],
                default => ++$resumo['falhou'],
            };
        }

        return $resumo;
    }

    /** Aplica o que a pasta do pedido diz; devolve se a pasta deve ser apagada depois de gravar. */
    private function conferir(PublicacaoDosBlogs $publicacao): bool
    {
        $id = (int) $publicacao->getId();
        $agora = $this->relogio->now();
        $resultado = $this->pasta->resultado($id);
        $iniciado = $this->pasta->iniciado($id);
        if ($resultado !== null) {
            $this->aplicarResultado($publicacao, $resultado, self::data($iniciado), $agora);

            return true;
        }
        if ($iniciado !== null) {
            $publicacao->marcarGerando(self::data($iniciado) ?? $agora);
        }
        $desde = $publicacao->getIniciadaEm() ?? $publicacao->getPedidaEm();
        if ($desde->modify(self::PRAZO_DO_BUILDER) > $agora) {
            return false;
        }
        $publicacao->falhar($iniciado !== null
            ? 'O builder não respondeu em 30 minutos.'
            : 'O builder não começou a gerar os blogs em 30 minutos.', $agora);

        return true;
    }

    /** @param array<string, mixed> $resultado */
    private function aplicarResultado(PublicacaoDosBlogs $publicacao, array $resultado, ?\DateTimeImmutable $iniciado, \DateTimeImmutable $agora): void
    {
        $situacao = $resultado['situacao'] ?? null;
        if (!\in_array($situacao, [SituacaoDaPublicacao::NoAr->value, SituacaoDaPublicacao::Falhou->value], true)) {
            $publicacao->falhar('O builder devolveu um resultado que o BlueJus não entende.', $agora);

            return;
        }

        $publicacao->concluir(
            SituacaoDaPublicacao::NoAr->value === $situacao,
            \is_string($resultado['commit'] ?? null) ? $resultado['commit'] : null,
            \is_string($resultado['log'] ?? null) ? (string) preg_replace(self::COR_ANSI, '', $resultado['log']) : '',
            self::data($resultado['iniciadaEm'] ?? null) ?? $iniciado,
            self::data($resultado['terminadaEm'] ?? null) ?? $agora,
        );
    }

    private static function data(mixed $iso): ?\DateTimeImmutable
    {
        if (!\is_string($iso) || trim($iso) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable(trim($iso)))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }
}
