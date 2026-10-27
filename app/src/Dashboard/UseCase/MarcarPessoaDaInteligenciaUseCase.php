<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\MarcaDaPessoaOutput;
use App\Dashboard\DTO\MarcarPessoaDaInteligenciaInput;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Preferencia\MarcasDasPessoas;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Repository\UserRepository;

/**
 * Marca a análise de uma pessoa para o usuário LOGADO ("Marcar como analisado" e "Essa análise
 * ajudou?", dc L2457-2463). A marca é pessoal: vale só para esse usuário neste escritório (linha em
 * `preferencia_usuario`); tocar de novo na marca ativa a desfaz. Cada marca vai ao `audit_log`.
 *
 * Erros: ação fora da lista → {@see PreferenciaInvalidaException}; pessoa que não é colaboradora ativa
 * do escritório → {@see ColaboradorForaDoEscritorioException} (IDOR). Nos dois, nada é gravado.
 */
final class MarcarPessoaDaInteligenciaUseCase
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PreferenciaDoUsuarioRepository $preferencias,
        private readonly RegistrarAuditoriaDoDesempenhoIndividualUseCase $auditoria,
    ) {
    }

    /**
     * @throws ColaboradorForaDoEscritorioException
     * @throws PreferenciaInvalidaException
     */
    public function executar(MarcarPessoaDaInteligenciaInput $input): MarcaDaPessoaOutput
    {
        if ($this->userRepository->findColaboradorAtivoPorIdETenant($input->pessoaId, $input->tenant) === null) {
            throw new ColaboradorForaDoEscritorioException();
        }

        $gravado = $this->preferencias->valoresDoUsuario($input->tenant, $input->usuario, [MarcasDasPessoas::CHAVE]);
        $mapa    = MarcasDasPessoas::lerOuVazio($gravado[MarcasDasPessoas::CHAVE] ?? null);
        $novo    = MarcasDasPessoas::alternar($mapa, $input->pessoaId, $input->acao);

        $this->preferencias->gravar($input->tenant, $input->usuario, MarcasDasPessoas::CHAVE, $novo);
        $this->auditoria->marcou($input->tenant, $input->usuario, $input->pessoaId, $input->acao);

        $marca = $novo[$input->pessoaId] ?? ['status' => null, 'feedback' => null];

        return new MarcaDaPessoaOutput($input->pessoaId, $marca['status'], $marca['feedback']);
    }
}
