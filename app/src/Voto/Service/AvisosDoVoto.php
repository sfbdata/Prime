<?php

declare(strict_types=1);

namespace App\Voto\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Avisos das telas da assembleia (o toast do desenho, L281) e o relatório da última importação, guardados na
 * sessão até a próxima tela que os mostra.
 *
 * Não usa o flash bag: o base.html.twig consome todos os flashes antes do bloco `body` e os desenha como alerta do
 * AdminLTE, e o desenho pede o toast. Chaves próprias na sessão ficam fora desse laço.
 */
final class AvisosDoVoto
{
    private const AVISOS = 'voto_assembleia_avisos';
    private const RELATORIO = 'voto_assembleia_importacao';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function ok(string $texto): void
    {
        $this->guardar('ok', $texto);
    }

    public function erro(string $texto): void
    {
        $this->guardar('erro', $texto);
    }

    /**
     * Os avisos pendentes, que saem da sessão ao serem lidos.
     *
     * @return list<array{tipo: string, texto: string}>
     */
    public function retirar(): array
    {
        $sessao = $this->requestStack->getCurrentRequest()?->hasSession() ? $this->requestStack->getSession() : null;
        if (null === $sessao) {
            return [];
        }
        $avisos = $sessao->get(self::AVISOS, []);
        $sessao->remove(self::AVISOS);

        return \is_array($avisos) ? array_values($avisos) : [];
    }

    /** @param array{invalidas: list<array{linha: int, motivo: string}>, duplicadas: list<int>} $relatorio */
    public function guardarRelatorio(array $relatorio): void
    {
        $this->requestStack->getSession()->set(self::RELATORIO, $relatorio);
    }

    /** @return array{invalidas: list<array{linha: int, motivo: string}>, duplicadas: list<int>}|null */
    public function retirarRelatorio(): ?array
    {
        $sessao = $this->requestStack->getSession();
        $relatorio = $sessao->get(self::RELATORIO);
        $sessao->remove(self::RELATORIO);

        return \is_array($relatorio) ? $relatorio : null;
    }

    private function guardar(string $tipo, string $texto): void
    {
        $sessao = $this->requestStack->getSession();
        $avisos = $sessao->get(self::AVISOS, []);
        $avisos = \is_array($avisos) ? $avisos : [];
        $avisos[] = ['tipo' => $tipo, 'texto' => $texto];
        $sessao->set(self::AVISOS, $avisos);
    }
}
