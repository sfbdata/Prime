<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O que o modal do link externo e o menu da aba Push precisam (C.3/C.6/C.7): os links da pasta,
 * o resumo "n links · n ativos · último dd/mm hh:mm" ([EXP] 5211), o total de acessos e se a pasta
 * pode gerar um link agora (com o motivo do bloqueio, texto do desenho).
 */
final readonly class LinksExternosDaPastaOutput
{
    /**
     * @param LinkExternoDoPushOutput[] $links
     */
    public function __construct(
        public array $links,
        public bool $podeGerar,
        public ?string $motivoBloqueio,
        public int $analisesCompartilhaveis,
        public ?\DateTimeImmutable $ultimoAcesso,
    ) {
    }

    public function totalAtivos(): int
    {
        return count(array_filter($this->links, static fn (LinkExternoDoPushOutput $l): bool => $l->estado === 'ativo'));
    }

    public function totalAcessos(): int
    {
        return array_sum(array_map(static fn (LinkExternoDoPushOutput $l): int => $l->acessos['total'], $this->links));
    }

    public function resumo(): string
    {
        $links = count($this->links);
        $ativos = $this->totalAtivos();
        $texto = $links . ($links === 1 ? ' link' : ' links') . ' · ' . $ativos . ($ativos === 1 ? ' ativo' : ' ativos');

        return $this->ultimoAcesso !== null ? $texto . ' · último ' . $this->ultimoAcesso->format('d/m H:i') : $texto;
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        return [
            'links' => array_map(static fn (LinkExternoDoPushOutput $l): array => $l->paraJson(), $this->links),
            'resumo' => $this->resumo(),
            'totalLinks' => count($this->links),
            'totalAtivos' => $this->totalAtivos(),
            'totalAcessos' => $this->totalAcessos(),
            'ultimoAcesso' => $this->ultimoAcesso?->format('d/m/Y H:i'),
            'podeGerar' => $this->podeGerar,
            'motivoBloqueio' => $this->motivoBloqueio,
            'analisesCompartilhaveis' => $this->analisesCompartilhaveis,
        ];
    }
}
