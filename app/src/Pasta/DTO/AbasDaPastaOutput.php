<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;

/**
 * O que a tela da pasta precisa saber das abas ocultas, já decidido no servidor (spec
 * pasta-ocultar-aba §5): quais abas este usuário NÃO vê e, para quem configura (Master ou primário),
 * a regra de cada aba (o ícone `bi-eye-slash`, o title e o título do menu do botão direito).
 *
 * Quem configura nunca é ocultado: para ele `ocultas` é sempre vazio.
 */
final readonly class AbasDaPastaOutput
{
    /** A ordem das abas no cabeçalho (`pasta/_cabecalho.html.twig`). */
    public const ORDEM = ['dados', 'tarefas', 'processo', 'financeiro', 'detalhes', 'documentos', 'push'];

    /**
     * @param list<string>                                          $ocultas abas que este usuário não vê
     * @param array<string, array{paraTodos: bool, quantidade: int, alcancados: int}> $regras só para quem
     *                                                                 configura; `quantidade` = tamanho da lista
     *                                                                 gravada, `alcancados` = elegíveis que a regra esconde
     */
    public function __construct(
        public array $ocultas,
        public bool $podeConfigurar,
        public array $regras,
    ) {
    }

    /**
     * @param list<AbaOcultaDaPasta> $regras  todas as regras da pasta
     * @param list<AbaDaPasta>       $ocultas   as que alcançam este usuário
     * @param list<int>              $elegiveis ids de quem pode ter a aba oculta (`EquipeQuePodeTerAbaOculta`)
     */
    public static function montar(array $regras, array $ocultas, bool $podeConfigurar, array $elegiveis = []): self
    {
        $porAba = [];
        if ($podeConfigurar) {
            foreach ($regras as $regra) {
                $porAba[$regra->getAba()->value] = [
                    'paraTodos'  => $regra->isParaTodos(),
                    'quantidade' => \count($regra->getUsuarios()),
                    'alcancados' => \count(array_filter($elegiveis, static fn (int $id): bool => $regra->alcanca($id))),
                ];
            }
        }

        return new self(
            array_map(static fn (AbaDaPasta $aba): string => $aba->value, $ocultas),
            $podeConfigurar,
            $porAba,
        );
    }

    /** Sem regra nenhuma (ou sem escritório na sessão): tudo visível, ninguém configura. */
    public static function todasVisiveis(): self
    {
        return new self([], false, []);
    }

    public function visivel(string $aba): bool
    {
        return !\in_array($aba, $this->ocultas, true);
    }

    /** A aba que nasce ativa: a primeira visível na ordem do cabeçalho; `null` se nenhuma. */
    public function primeiraVisivel(): ?string
    {
        foreach (self::ORDEM as $aba) {
            if ($this->visivel($aba)) {
                return $aba;
            }
        }

        return null;
    }

    /** O contrato de honorários some com o Financeiro oculto (regra de `VisibilidadeDasAbasDaPasta`). */
    public function documentoVisivel(?string $categoria): bool
    {
        return VisibilidadeDasAbasDaPasta::documentoVisivel($categoria, $this->ocultas);
    }

    public function temRegra(string $aba): bool
    {
        return isset($this->regras[$aba]);
    }

    /** O title do ícone da aba ([EXP] L.6088 `ocultaTit`). */
    public function dicaDoIcone(string $aba): string
    {
        $regra = $this->regras[$aba] ?? null;
        if ($regra === null) {
            return '';
        }

        return $regra['paraTodos']
            ? 'Oculta para toda a equipe'
            : 'Oculta para ' . $regra['quantidade'] . ' pessoa(s)';
    }

    /**
     * O complemento do título do menu do botão direito ([EXP] L.4412-4414): " · oculta para N", com N =
     * quantos da equipe a regra esconde, também em "toda a equipe". Sem ninguém alcançado, sem sufixo.
     */
    public function sufixoDoMenu(string $aba): string
    {
        $alcancados = $this->regras[$aba]['alcancados'] ?? 0;

        return $alcancados > 0 ? ' · oculta para ' . $alcancados : '';
    }
}
