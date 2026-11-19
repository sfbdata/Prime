<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\FormatoDeData;
use App\Profile\Enum\FormatoDeNumero;
use App\Profile\Enum\FusoHorario;
use App\Profile\Enum\Idioma;
use App\Profile\Exception\IdiomaIndisponivelException;
use App\Profile\Exception\OpcaoAvancadaInvalidaException;
use App\Profile\Repository\UserProfileRepository;

/**
 * Grava as "Opções avançadas" do Meu Perfil (spec docs/specs/sistema-em-ingles.md, §2): idioma, fuso horário,
 * formato de data e de números, de uma vez.
 *
 * Quem: a própria pessoa, logada (vale só para ela; não depende de escritório). Vazio ou nulo no fuso e nos
 * formatos = o padrão (Brasília; automático, pelo idioma), gravado como nulo. Tudo é conferido antes de gravar:
 * idioma desconhecido ou "em breve" e opção fora da lista recusam sem mudar nada. O perfil nasce se ainda não
 * existir, como nas outras preferências.
 */
final class SalvarOpcoesAvancadasUseCase
{
    public function __construct(
        private readonly ObterOuCriarPerfilUseCase $obterOuCriarPerfil,
        private readonly UserProfileRepository $perfis,
    ) {
    }

    /**
     * @throws IdiomaIndisponivelException
     * @throws OpcaoAvancadaInvalidaException
     */
    public function executar(User $usuario, string $idioma, ?string $fuso, ?string $formatoData, ?string $formatoNumero): UserProfile
    {
        $escolhido = Idioma::tryFrom($idioma);
        if ($escolhido === null || !$escolhido->ativo()) {
            throw new IdiomaIndisponivelException();
        }
        $fusoEscolhido = self::opcao(FusoHorario::class, $fuso);
        $dataEscolhida = self::opcao(FormatoDeData::class, $formatoData);
        $numeroEscolhido = self::opcao(FormatoDeNumero::class, $formatoNumero);

        $perfil = $this->obterOuCriarPerfil->executar($usuario);
        $perfil->setIdioma($escolhido)
            ->setFusoHorario($fusoEscolhido === FusoHorario::PADRAO ? null : $fusoEscolhido)
            ->setFormatoData($dataEscolhida)
            ->setFormatoNumero($numeroEscolhido);
        $this->perfis->salvar($perfil, flush: true);

        return $perfil;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    private static function opcao(string $enum, ?string $valor): ?\BackedEnum
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return $enum::tryFrom($valor) ?? throw new OpcaoAvancadaInvalidaException();
    }
}
