<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\DTO\PedidoDeAutorizacao;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Guarda o pedido do `/oauth/authorize` na sessão entre o GET (tela) e o POST (autorizar), como a 9.4 manda: os
 * parâmetros OAuth nunca vão em campo oculto, então o POST não consegue trocar `redirect_uri`, `state`, desafio PKCE,
 * `resource` nem escopos.
 *
 * Cada pedido tem um id aleatório (256 bits) e é de USO ÚNICO: {@see retirar()} o tira da sessão. Vale 30 minutos e a
 * sessão guarda no máximo {@see MAXIMO} pedidos (o mais antigo sai), para várias abas não encherem a sessão.
 */
final class PedidoDeAutorizacaoNaSessao
{
    private const CHAVE = 'conector_oauth_pedidos';
    private const MAXIMO = 5;
    private const VALIDADE_SEGUNDOS = 1800;

    /**
     * Os pedidos que terminaram em conexão, por alguns minutos: o segundo clique em "Autorizar" (o navegador ainda
     * carregando a volta ao app) chega com um pedido já usado e deve ver "conexão feita", não "pedido inválido".
     */
    private const CHAVE_CONCLUIDOS = 'conector_oauth_concluidos';
    private const VALIDADE_CONCLUIDO_SEGUNDOS = 600;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** Guarda o pedido e devolve o id que vai no formulário. */
    public function guardar(PedidoDeAutorizacao $pedido): string
    {
        $id = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $pedidos = $this->vigentes();
        $pedidos[$id] = ['criado_em' => $this->relogio->now()->getTimestamp(), 'pedido' => $pedido->paraSessao()];
        $this->gravar(\array_slice($pedidos, -self::MAXIMO, null, true));

        return $id;
    }

    /**
     * O pedido do id SEM tirá-lo da sessão: para voltar à tela quando falta uma escolha (o escritório) e o pedido
     * continua valendo. Nulo se não existe, venceu ou já foi usado.
     */
    public function ler(?string $id): ?PedidoDeAutorizacao
    {
        $guardado = $id !== null && $id !== '' ? ($this->vigentes()[$id] ?? null) : null;

        return $guardado === null ? null : PedidoDeAutorizacao::daSessao($guardado['pedido']);
    }

    /** O pedido do id, tirado da sessão (uso único); nulo se não existe, venceu ou já foi usado. */
    public function retirar(?string $id): ?PedidoDeAutorizacao
    {
        $pedidos = $this->vigentes();
        $guardado = $id !== null && $id !== '' ? ($pedidos[$id] ?? null) : null;
        if ($guardado !== null) {
            unset($pedidos[$id]);
        }
        $this->gravar($pedidos);

        return $guardado === null ? null : PedidoDeAutorizacao::daSessao($guardado['pedido']);
    }

    /** Marca o pedido como concluído em conexão (o código já foi emitido). Não volta a valer: só muda a recusa. */
    public function marcarConcluido(?string $id): void
    {
        if ($id === null || $id === '') {
            return;
        }

        $concluidos = $this->concluidosVigentes();
        $concluidos[$id] = $this->relogio->now()->getTimestamp();
        $this->requestStack->getSession()->set(self::CHAVE_CONCLUIDOS, \array_slice($concluidos, -self::MAXIMO, null, true));
    }

    /** O pedido deste id terminou em conexão há pouco, nesta mesma sessão? */
    public function foiConcluido(?string $id): bool
    {
        return $id !== null && $id !== '' && isset($this->concluidosVigentes()[$id]);
    }

    /** @return array<string, int> */
    private function concluidosVigentes(): array
    {
        $guardados = $this->requestStack->getSession()->get(self::CHAVE_CONCLUIDOS, []);
        if (!\is_array($guardados)) {
            return [];
        }

        $limite = $this->relogio->now()->getTimestamp() - self::VALIDADE_CONCLUIDO_SEGUNDOS;

        return array_filter($guardados, static fn (mixed $quando, mixed $id): bool => \is_string($id) && \is_int($quando) && $quando > $limite, \ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<string, array{criado_em: int, pedido: array<mixed>}> */
    private function vigentes(): array
    {
        $guardados = $this->requestStack->getSession()->get(self::CHAVE, []);
        if (!\is_array($guardados)) {
            return [];
        }

        $limite = $this->relogio->now()->getTimestamp() - self::VALIDADE_SEGUNDOS;
        $vigentes = [];
        foreach ($guardados as $id => $guardado) {
            if (\is_string($id) && \is_array($guardado) && \is_int($guardado['criado_em'] ?? null) && $guardado['criado_em'] > $limite && \is_array($guardado['pedido'] ?? null)) {
                $vigentes[$id] = ['criado_em' => $guardado['criado_em'], 'pedido' => $guardado['pedido']];
            }
        }

        return $vigentes;
    }

    /** @param array<string, array{criado_em: int, pedido: array<mixed>}> $pedidos */
    private function gravar(array $pedidos): void
    {
        $sessao = $this->requestStack->getSession();
        if ($pedidos === []) {
            $sessao->remove(self::CHAVE);

            return;
        }

        $sessao->set(self::CHAVE, $pedidos);
    }
}
