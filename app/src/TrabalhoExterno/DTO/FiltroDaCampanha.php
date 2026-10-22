<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\DTO;

/**
 * O que o Master escolheu no modal "Campanha por e-mail" (desenho 06 l.330 a 341; spec VS 18.10.26
 * §4.10): o público, a UF, a área e o assunto. Valor fora da lista vira o padrão ("Candidatos", "Todas
 * as UFs", "Todas as áreas"); o assunto vazio (ou ausente) é o assunto padrão do público.
 */
final readonly class FiltroDaCampanha
{
    public const CANDIDATOS = 'cand';
    public const ESCRITORIOS = 'esc';
    private const MAX_ASSUNTO = 200;

    private function __construct(
        public string $pub,
        public string $uf,
        public string $area,
        public ?string $assunto,
    ) {
    }

    /** @param array<string, mixed> $d os parâmetros `pub`, `uf`, `area` e `assunto` (query ou corpo) */
    public static function deArray(array $d): self
    {
        $pub = ($d['pub'] ?? '') === self::ESCRITORIOS ? self::ESCRITORIOS : self::CANDIDATOS;
        $uf = is_string($d['uf'] ?? null) && in_array($d['uf'], DadosDaSolicitacao::UFS, true) ? $d['uf'] : '';
        $area = is_string($d['area'] ?? null) && in_array($d['area'], DadosDaVaga::AREAS, true) ? $d['area'] : '';
        $assunto = is_string($d['assunto'] ?? null) ? trim(mb_substr($d['assunto'], 0, self::MAX_ASSUNTO)) : '';

        return new self($pub, $uf, $area, $assunto !== '' ? $assunto : null);
    }

    public function ehEscritorios(): bool
    {
        return $this->pub === self::ESCRITORIOS;
    }

    /** @return array{pub: string, uf: string, area: string} o que vai para o log (o assunto não é filtro) */
    public function paraOLog(): array
    {
        return ['pub' => $this->pub, 'uf' => $this->uf, 'area' => $this->area];
    }
}
