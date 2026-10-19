<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Pasta\DTO\AnaliseCompartilhadaOutput;
use App\Pasta\DTO\MovimentacaoCompartilhadaOutput;
use App\Pasta\DTO\PaginaDoPushCompartilhadoOutput;
use App\Pasta\Entity\AcessoAoLinkExternoDoPush;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Repository\AcessoAoLinkExternoDoPushRepository;
use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Pasta\Service\ConteudoDoPushCompartilhado;
use Psr\Clock\ClockInterface;

/**
 * Abrir a página pública do link externo (spec push-link-externo §2.5). Público, sem sessão.
 *
 * Quem: o terceiro que recebeu o endereço (e, se exigido, o código) do escritório.
 * Ordem de decisão: não encontrado (ou link que deixou de ser visível) > revogado > expirado > bloqueado > pede
 * código > ok. O código é conferido em tempo constante; erro soma tentativa (5 seguidas bloqueiam
 * por 15 min) e acerto zera. Só o `ok` grava um acesso.
 *
 * Toda leitura depois do endereço usa o tenant DO LINK: aqui não há escritório na sessão.
 */
final class AbrirLinkExternoDoPushUseCase
{
    public const ERRO_CODIGO = 'Código incorreto. Confira e tente de novo.';

    public function __construct(
        private readonly LinkExternoDoPushRepository $links,
        private readonly AcessoAoLinkExternoDoPushRepository $acessos,
        private readonly ConteudoDoPushCompartilhado $conteudo,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** `$codigo` nulo = abertura sem formulário (GET); string = o que o terceiro digitou (POST). */
    public function executar(string $endereco, ?string $codigo = null): PaginaDoPushCompartilhadoOutput
    {
        $agora = $this->relogio->now();
        $link = $this->links->findOnePeloEndereco($endereco);

        // A pasta mudou depois de criar o link (excluída, trancada, cancelada, arquivada, processo
        // sigiloso ou desvinculado): a página não confirma que o link existe nem mostra dado.
        if ($link === null || !$this->conteudo->linkVisivel($link)) {
            return PaginaDoPushCompartilhadoOutput::naoEncontrado($endereco);
        }
        if ($link->estaRevogado()) {
            return PaginaDoPushCompartilhadoOutput::revogado($link);
        }
        if ($link->estaExpirado($agora)) {
            return PaginaDoPushCompartilhadoOutput::expirado($link, $agora);
        }

        return $link->exigeCodigo()
            ? $this->abrirComCodigo($link, $codigo, $agora)
            : $this->mostrar($link, $agora);
    }

    private function abrirComCodigo(LinkExternoDoPush $link, ?string $codigo, \DateTimeImmutable $agora): PaginaDoPushCompartilhadoOutput
    {
        if ($link->estaBloqueado($agora)) {
            return PaginaDoPushCompartilhadoOutput::bloqueado($link);
        }
        if ($codigo === null) {
            return PaginaDoPushCompartilhadoOutput::pedeCodigo($link);
        }

        $digitado = self::soDigitos($codigo);
        $conferiu = $this->links->alterarComTrava(
            $link,
            static fn (LinkExternoDoPush $l): bool => $l->conferirCodigo($digitado, $agora),
        );
        if ($conferiu) {
            return $this->mostrar($link, $agora);
        }

        return $link->estaBloqueado($agora)
            ? PaginaDoPushCompartilhadoOutput::bloqueado($link)
            : PaginaDoPushCompartilhadoOutput::pedeCodigo($link, self::ERRO_CODIGO);
    }

    private function mostrar(LinkExternoDoPush $link, \DateTimeImmutable $agora): PaginaDoPushCompartilhadoOutput
    {
        $pasta = $link->getPasta();
        $tenant = $link->getTenant();

        $this->acessos->salvar(AcessoAoLinkExternoDoPush::do($link, $agora), true);

        return PaginaDoPushCompartilhadoOutput::ok(
            $link,
            $link->mostraCliente() ? $this->conteudo->nomeDoCliente($pasta) : null,
            $link->incluiAnalises() ? array_map(
                static fn (AnaliseDeInteligencia $a): AnaliseCompartilhadaOutput => AnaliseCompartilhadaOutput::fromEntity($a),
                $this->conteudo->analisesCompartilhaveis($pasta, $tenant),
            ) : [],
            array_map(MovimentacaoCompartilhadaOutput::fromItem(...), $this->conteudo->movimentacoes($pasta, $tenant)),
        );
    }

    private static function soDigitos(string $codigo): string
    {
        return substr((string) preg_replace('/\D+/', '', $codigo), 0, 6);
    }
}
