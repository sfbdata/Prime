<?php

declare(strict_types=1);

namespace App\Tarefa\Twig;

use App\Entity\Tenant\Tenant;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Service\NomesDasEtapas;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * O campo "Caixa / etapa" da Nova meta (desenho 02 de 22/10, L179 e L4049 `NMF`/`nmSug`): as 8 etapas na ordem da
 * esteira, com o nome que o escritório deu ({@see NomesDasEtapas}) e o setor do catálogo, e a regra de sugestão
 * pelo título, tirada do próprio enum para o JS não manter uma segunda lista.
 */
final class EtapasDaNovaMetaExtension extends AbstractExtension
{
    public function __construct(
        private readonly NomesDasEtapas $nomes,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('etapas_da_nova_meta', $this->etapas(...)),
            new TwigFunction('regra_da_etapa_pelo_titulo', $this->regra(...)),
        ];
    }

    /**
     * Sem escritório (pasta sem tenant, que não deveria existir), o nome do catálogo.
     *
     * @return list<array{valor: string, nome: string, setor: string}>
     */
    public function etapas(?Tenant $escritorio): array
    {
        return array_map(fn (EtapaDaPasta $etapa): array => [
            'valor' => $etapa->value,
            'nome'  => $escritorio === null ? $this->tradutor->trans($etapa->rotulo()) : $this->nomes->nome($escritorio, $etapa),
            'setor' => $this->nomes->setor($etapa),
        ], EtapaDaPasta::cases());
    }

    /**
     * As regras, na ordem de teste, e a etapa de quem não casa nenhuma (a do título sem palavra-chave, Jurídico).
     *
     * @return array{regras: list<array{padrao: string, etapa: string}>, padrao: string}
     */
    public function regra(): array
    {
        return [
            'regras' => EtapaDaPasta::regrasDoTitulo(),
            'padrao' => EtapaDaPasta::deduzirDoTitulo('')->value,
        ];
    }
}
