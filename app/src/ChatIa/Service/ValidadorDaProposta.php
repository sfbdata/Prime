<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\DTO\ResultadoDaValidacao;
use App\ChatIa\Enum\TipoDaProposta;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Exception\CadastroInvalidoException;
use App\Cliente\Exception\DocumentoJaCadastradoException;
use App\Cliente\Service\CamposDoCadastro;
use App\Cliente\UseCase\CriarClienteDaPastaUseCase;
use App\Entity\Tenant\Tenant;

/**
 * Valida os `dados` de uma proposta SEM gravar nada, com as mesmas regras do cadastro ({@see CamposDoCadastro::aplicar},
 * que é quem o UseCase usa): limites das colunas, e-mail, CPF/CNPJ com dígito, data, estado civil, UF e CPF/CNPJ de
 * outro cadastro do escritório. Cada campo é aplicado sozinho num cadastro em branco que nunca é persistido, para o erro
 * apontar O campo. Pasta: nome do cliente e da ação obrigatórios (até 255), número até 50.
 */
final class ValidadorDaProposta
{
    public function __construct(private readonly CamposDoCadastro $campos)
    {
    }

    /** @param array<string, mixed> $dados */
    public function validar(TipoDaProposta $tipo, array $dados, Tenant $tenant): ResultadoDaValidacao
    {
        if ($tipo === TipoDaProposta::Cliente) {
            return $this->validarCliente($dados, $tenant);
        }

        $erros = [];
        foreach (['nomeCliente' => 'Informe o nome do cliente.', 'nomeAcao' => 'Informe o nome da ação.'] as $chave => $vazio) {
            $valor = trim((string) ($dados[$chave] ?? ''));
            if ($valor === '') {
                $erros['pasta.' . $chave] = $vazio;
            } elseif (mb_strlen($valor) > 255) {
                $erros['pasta.' . $chave] = 'Passa de 255 caracteres.';
            }
        }
        if (mb_strlen(trim((string) ($dados['nup'] ?? ''))) > 50) {
            $erros['pasta.nup'] = 'O número passa de 50 caracteres.';
        }
        $cliente = \is_array($dados['cliente'] ?? null) ? $dados['cliente'] : null;
        if ($cliente === null) {
            return new ResultadoDaValidacao($erros);
        }

        $doCliente = $this->validarCliente($cliente, $tenant);

        return new ResultadoDaValidacao($erros + $doCliente->erros, $doCliente->existente, $doCliente->documento);
    }

    /** @param array<string, mixed> $dados `{tipoPessoa, campos}` */
    private function validarCliente(array $dados, Tenant $tenant): ResultadoDaValidacao
    {
        $tipoPessoa = (string) ($dados['tipoPessoa'] ?? '');
        $campos = \is_array($dados['campos'] ?? null) ? $dados['campos'] : [];
        if (!CamposDaProposta::tipoValido($tipoPessoa) || $campos === []) {
            return new ResultadoDaValidacao(['' => 'Informe ao menos um dado do cliente.']);
        }

        $erros = [];
        $existente = null;
        foreach ($campos as $chave => $valor) {
            $emBranco = $tipoPessoa === CamposDaProposta::PJ ? CriarClienteDaPastaUseCase::pjEmBranco() : CriarClienteDaPastaUseCase::pfEmBranco();
            try {
                $this->campos->aplicar($emBranco, [(string) $chave => (string) $valor], $tenant);
            } catch (CadastroInvalidoException $e) {
                $erros['campos.' . $chave] = $e->getMessage();
            } catch (DocumentoJaCadastradoException $e) {
                $existente ??= $e->existente;
            }
        }

        return new ResultadoDaValidacao($erros, $existente, $existente === null ? null : self::documentoDe($existente));
    }

    private static function documentoDe(Cliente $existente): string
    {
        return $existente instanceof ClientePF ? 'CPF' : 'CNPJ';
    }
}
