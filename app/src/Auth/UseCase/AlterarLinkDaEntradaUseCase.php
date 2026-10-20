<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\AlterarLinkDaEntradaInput;
use App\Auth\Entity\AjusteEntrada;
use App\Auth\Repository\AjusteEntradaRepository;
use App\Auth\Service\PaginaDeEntrada;
use App\Entity\Auth\User;

/**
 * "Editar link · <Nome>" do rodapé da tela de entrada (desenho 10, SÓ MASTER): Salvar, Restaurar e
 * Ocultar/Mostrar. Restaurar apaga o ajuste: o link volta ao endereço padrão do desenho e fica visível.
 *
 * Quem é Master é conferido no controller.
 *
 * @throws \DomainException com o texto para a tela (endereço inválido, link ou ação desconhecidos)
 */
final class AlterarLinkDaEntradaUseCase
{
    /** O mesmo teste de endereço do desenho. */
    private const ENDERECO_VALIDO = '/^https?:\/\/[^\s.]+\.[^\s]+$/i';

    public function __construct(
        private readonly AjusteEntradaRepository $ajustes,
    ) {
    }

    public function executar(AlterarLinkDaEntradaInput $input): void
    {
        if (!array_key_exists($input->chave, PaginaDeEntrada::LINKS)) {
            throw new \DomainException('Link desconhecido.');
        }
        $chave = AjusteEntrada::chaveDoLink($input->chave);

        if ($input->acao === 'salvar') {
            $this->salvarEndereco($chave, trim((string) $input->url), $input->alteradoPor);

            return;
        }
        if ($input->acao === 'restaurar') {
            $this->restaurarPadrao($chave);

            return;
        }
        if ($input->acao === 'ocultar' || $input->acao === 'mostrar') {
            $this->definirOculto($chave, $input->acao === 'ocultar', $input->alteradoPor);

            return;
        }

        throw new \DomainException('Ação inválida.');
    }

    private function salvarEndereco(string $chave, string $url, User $por): void
    {
        if (preg_match(self::ENDERECO_VALIDO, $url) !== 1) {
            throw new \DomainException('Informe um endereço começando com https://');
        }
        $ajuste = $this->ajusteOuNovo($chave);
        $ajuste->definirValor($url, $por, new \DateTimeImmutable());
        $this->ajustes->salvar($ajuste, flush: true);
    }

    private function restaurarPadrao(string $chave): void
    {
        $ajuste = $this->ajustes->daChave($chave);
        if ($ajuste === null) {
            return;
        }
        $this->ajustes->remover($ajuste, flush: true);
    }

    private function definirOculto(string $chave, bool $oculto, User $por): void
    {
        $ajuste = $this->ajusteOuNovo($chave);
        $ajuste->definirOculto($oculto, $por, new \DateTimeImmutable());
        $this->ajustes->salvar($ajuste, flush: true);
    }

    private function ajusteOuNovo(string $chave): AjusteEntrada
    {
        return $this->ajustes->daChave($chave) ?? new AjusteEntrada($chave);
    }
}
