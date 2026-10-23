<?php

declare(strict_types=1);

namespace App\Profile\Enum;

/**
 * Gênero declarado pela pessoa (desenho 05 de 21/10, filtro Gênero; specs docs/specs/meu-perfil-genero-atuacao.md e
 * docs/specs/genero-no-cadastro.md). Obrigatório na criação da conta; opcional no Meu Perfil.
 *
 * Só Feminino e Masculino são oferecidos (D1 da spec genero-no-cadastro, decisão do dono de 23/10/2026). O caso
 * `NaoInformado` é LEGADO: fica para não quebrar a leitura de um valor antigo, nunca é oferecido nem aceito de um
 * formulário, e conta como NÃO preenchido (a janela pede o gênero, o Dr./Dra. não sai).
 */
enum Genero: string
{
    case Feminino     = 'feminino';
    case Masculino    = 'masculino';
    /** Legado (D1): nunca oferecido, conta como não preenchido. */
    case NaoInformado = 'nao_informado';

    /** Recusa de quem não escolheu (ou mandou um valor fora das opções). A chave do catálogo fica ao lado. */
    public const ERRO_INFORME = 'Informe o gênero.';
    public const CHAVE_DO_ERRO = 'moldura.genero.informe';

    public function rotulo(): string
    {
        return match ($this) {
            self::Feminino     => 'Feminino',
            self::Masculino    => 'Masculino',
            self::NaoInformado => 'Prefiro não informar',
        };
    }

    /** @return list<self> as opções dos formulários */
    public static function oferecidos(): array
    {
        return [self::Feminino, self::Masculino];
    }

    /** O valor cru de um formulário de criação de conta; vazio, desconhecido ou o legado é recusado. */
    public static function informado(?string $valor): self
    {
        $genero = self::tryFrom(trim((string) $valor));
        if ($genero === null || !$genero->preenchido()) {
            throw new \InvalidArgumentException(self::ERRO_INFORME);
        }

        return $genero;
    }

    public function preenchido(): bool
    {
        return $this !== self::NaoInformado;
    }
}
