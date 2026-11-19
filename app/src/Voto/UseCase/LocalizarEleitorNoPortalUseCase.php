<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\Entity\Assembleia;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;

/**
 * Passo 1 do portal quando a lista tem CPF: acha a unidade por nome e CPF só para o passo 2 mostrar a unidade e o bloco
 * do cadastro. Não confirma nada ao chamador de fora: quem não casa recebe `null` e o fluxo segue igual (o código só
 * chega ao e-mail cadastrado). Como casar revela que o cadastro existe (é o pedido do dono: o eleitor ver a própria
 * unidade), o controller consome o limitador `votoPortalEnvioLimiter` do IP em cada tentativa antes de chamar.
 */
final class LocalizarEleitorNoPortalUseCase
{
    public function __construct(private readonly UnidadeDaAssembleiaRepository $unidades)
    {
    }

    /** @return array{unidade: string, bloco: string}|null a unidade e o bloco do cadastro, ou null quando nome e CPF não casam */
    public function executar(Assembleia $assembleia, string $nome, string $cpf): ?array
    {
        $unidade = $this->unidades->localizarPorNomeECpf($assembleia, $nome, $cpf);
        if (null === $unidade) {
            return null;
        }

        return ['unidade' => $unidade->getIdentificacao(), 'bloco' => $unidade->getBloco()];
    }
}
