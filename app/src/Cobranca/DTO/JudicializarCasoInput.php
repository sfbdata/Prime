<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Entrada da judicialização de um Caso de Cobrança (SPEC §16, e
 * `docs/specs/cobranca-judicializar-cria-pasta.md`).
 *
 * Dois modos, num formulário só:
 *
 * - `criar` (padrão): o sistema ABRE a pasta judicial na hora, com o nome do responsável principal e
 *   a ação `AÇÃO MONITÓRIA` — os dois pré-preenchidos e editáveis pelo gestor;
 * - `vincular`: o caminho antigo, para quem já abriu a pasta antes — escolhe uma pasta EXISTENTE do
 *   escritório.
 *
 * `casoId` vem da rota. A pasta e o caso são resolvidos por id + tenant (guarda multi-tenant,
 * invariável 1) no JudicializarCasoUseCase; aqui só se valida presença e formato.
 *
 * A validação é CONDICIONAL porque os campos obrigatórios mudam com o modo — e por isso vive num
 * `#[Assert\Callback]` (o padrão que os outros DTOs de mutação da Cobrança já usam) em vez de
 * atributos por campo, que exigiriam tudo ao mesmo tempo.
 */
final class JudicializarCasoInput
{
    public const MODO_CRIAR = 'criar';
    public const MODO_VINCULAR = 'vincular';

    /** A ação de TODOS os casos de cobrança — decisão do dono, spec §1. */
    public const ACAO_PADRAO = 'AÇÃO MONITÓRIA';

    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    /**
     * ANULÁVEL de propósito: o `ChoiceType` do formulário escreve `null` quando o radio não vem no
     * payload, e uma propriedade `string` estouraria com TypeError antes de qualquer validação. Quem
     * decide é `ehModoCriar()`, que trata ausente como criar — o modo padrão. Valor FORA da lista não
     * chega até aqui: o próprio ChoiceType o rejeita como escolha inválida.
     */
    #[Assert\Choice(choices: [self::MODO_CRIAR, self::MODO_VINCULAR], message: 'Escolha criar uma pasta ou vincular uma existente.')]
    public ?string $modo = self::MODO_CRIAR;

    /**
     * Nome do cliente da pasta nova. Vem pré-preenchido no padrão do escritório — `<fantasia do
     * credor da carteira> - <responsável principal>`, montado por `ComporNomeDaPastaJudicial` (spec
     * §2.5, decisão do dono de 2026-09-01). Antes disso vinha só o nome do responsável, e o prefixo
     * era digitado à mão. O limite é o da coluna `pasta.nome_cliente`.
     */
    #[Assert\Length(max: 255, maxMessage: 'O nome do cliente pode ter no máximo {{ limit }} caracteres.')]
    public ?string $nomeCliente = null;

    /** Ação da pasta nova; nasce em `AÇÃO MONITÓRIA` e o gestor pode corrigir. */
    #[Assert\Length(max: 255, maxMessage: 'A ação pode ter no máximo {{ limit }} caracteres.')]
    public ?string $nomeAcao = null;

    /** Só no modo `vincular`: a pasta EXISTENTE a ligar ao caso. */
    #[Assert\Positive(message: 'Pasta judicial inválida.')]
    public ?int $pastaId = null;

    public function ehModoCriar(): bool
    {
        return $this->modo !== self::MODO_VINCULAR;
    }

    /**
     * Cada modo exige o seu, e só o seu: criar pede nome do cliente e ação; vincular pede a pasta.
     * Sem isto, um formulário só teria de escolher entre exigir tudo (impossível de enviar) ou nada
     * (deixaria criar pasta sem nome).
     */
    #[Assert\Callback]
    public function validarPorModo(ExecutionContextInterface $context): void
    {
        if ($this->ehModoCriar()) {
            if (trim((string) $this->nomeCliente) === '') {
                $context->buildViolation('Informe o nome do cliente da pasta.')->atPath('nomeCliente')->addViolation();
            }

            if (trim((string) $this->nomeAcao) === '') {
                $context->buildViolation('Informe a ação da pasta.')->atPath('nomeAcao')->addViolation();
            }

            return;
        }

        if ($this->pastaId === null) {
            $context->buildViolation('Informe a pasta judicial.')->atPath('pastaId')->addViolation();
        }
    }
}
