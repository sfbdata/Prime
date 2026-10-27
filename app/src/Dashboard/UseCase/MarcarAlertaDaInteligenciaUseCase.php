<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\MarcaDoAlertaOutput;
use App\Dashboard\DTO\MarcarAlertaDaInteligenciaInput;
use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Preferencia\MarcasDosAlertas;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;

/**
 * Marca um alerta da Central de Inteligência para o usuário LOGADO (botões Resolvido, Ignorar,
 * Aplicar e Não se aplica do desenho). A marca é pessoal: vale só para esse usuário neste
 * escritório, e o alerta fica esmaecido na lista dele. Tocar de novo na marca ativa a desfaz.
 *
 * Erros: alerta ou ação fora das listas fechadas → {@see PreferenciaInvalidaException}, e nada é
 * gravado. Escritório e usuário vêm da sessão, nunca do corpo da requisição.
 */
final class MarcarAlertaDaInteligenciaUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $repository,
    ) {
    }

    /**
     * @throws PreferenciaInvalidaException
     */
    public function executar(MarcarAlertaDaInteligenciaInput $input): MarcaDoAlertaOutput
    {
        $gravado = $this->repository->valoresDoUsuario($input->tenant, $input->usuario, [MarcasDosAlertas::CHAVE]);
        $mapa    = MarcasDosAlertas::lerOuVazio($gravado[MarcasDosAlertas::CHAVE] ?? null);

        $novo = MarcasDosAlertas::alternar($mapa, $input->alerta, $input->acao);
        $this->repository->gravar($input->tenant, $input->usuario, MarcasDosAlertas::CHAVE, $novo);

        $marca = $novo[$input->alerta] ?? ['status' => null, 'feedback' => null];

        return new MarcaDoAlertaOutput($input->alerta, $marca['status'], $marca['feedback']);
    }
}
