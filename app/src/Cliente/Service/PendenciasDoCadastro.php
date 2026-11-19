<?php

declare(strict_types=1);

namespace App\Cliente\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * O que falta no cadastro de um cliente para ele servir de qualificação numa peça.
 *
 * Regra `cliPend` do desenho 1.2.3 (lista `CLI_REQ`), com os textos dele. Desde a E12f o cadastro tem número e
 * bairro, então nada do desenho fica omitido para pessoa física. Cada pendência diz a aba do Cadastro onde se
 * resolve (`pessoais`, `endereco`, `contato`, `docs`): é ela que acende o ponto laranja da aba e para onde o clique no
 * quadro "Pendências" leva.
 *
 * Pessoa jurídica (o desenho só desenha pessoa física; o dono decidiu adaptar): razão social, CNPJ, representante
 * legal e o CPF dele na aba Cliente; endereço, contato e documentos iguais.
 *
 * Documento VINCULADO do menu Documentos só resolve a pendência para quem vê a pasta dele
 * ({@see DocumentosVisiveisDoCadastro}): contar o vínculo de pasta escondida denunciaria que ela tem um documento do
 * cliente (spec cliente-inteligencia-cadastral §2.3, decisão do coordenador em 20/10). Sem quem olha, só o anexo
 * próprio conta.
 */
final class PendenciasDoCadastro
{
    public function __construct(
        private readonly ?DocumentosVisiveisDoCadastro $visiveis = null,
        private readonly ?Security $seguranca = null,
        private readonly ?TenantContext $tenants = null,
    ) {
    }

    /** @return list<string> */
    public function de(Cliente $cliente): array
    {
        return array_column($this->porAba($cliente), 'texto');
    }

    /** @return list<array{texto: string, aba: string}> */
    public function porAba(Cliente $cliente): array
    {
        return $this->montar($cliente, $this->documentosQueContam($cliente));
    }

    /**
     * O mesmo {@see porAba()} para quem olha dado por parâmetro, sem sessão nem `TenantContext` (o conector de I.A.,
     * pelo `LerClienteUseCase`): o documento vinculado conta só se a pessoa vê a pasta dele, neste escritório.
     *
     * @return list<array{texto: string, aba: string}>
     */
    public function porAbaVistoPor(Cliente $cliente, User $quemOlha, Tenant $escritorio): array
    {
        $documentos = $this->visiveis !== null
            ? $this->visiveis->de($cliente, $quemOlha, $escritorio)
            : self::soOsAnexosProprios($cliente);

        return $this->montar($cliente, $documentos);
    }

    /**
     * @param list<ClienteDocumento> $documentos os que resolvem a pendência de anexo para quem olha
     *
     * @return list<array{texto: string, aba: string}>
     */
    private function montar(Cliente $cliente, array $documentos): array
    {
        $pendencias = [];
        foreach ($this->camposObrigatorios($cliente) as [$rotulo, $valor, $aba, $genero]) {
            if (self::vazio($valor)) {
                $pendencias[] = ['texto' => $rotulo . ' não ' . ($genero === 'a' ? 'informada' : 'informado'), 'aba' => $aba];
            }
        }

        if (self::vazio($cliente->getTelefoneCelular())
            && self::vazio($cliente->getTelefoneFixo())
            && self::vazio($cliente->getWhatsapp())
            && self::vazio($cliente->getEmail())) {
            $pendencias[] = ['texto' => 'Nenhum contato informado', 'aba' => 'contato'];
        }

        if (!self::temAnexo($documentos, ClienteDocumento::CATEGORIA_IDENTIFICACAO)) {
            $pendencias[] = ['texto' => 'Documento de identificação não anexado', 'aba' => 'docs'];
        }

        if (!self::temAnexo($documentos, ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA)) {
            $pendencias[] = ['texto' => 'Comprovante de residência não anexado', 'aba' => 'docs'];
        }

        return $pendencias;
    }

    public function estaCompleto(Cliente $cliente): bool
    {
        return $this->porAba($cliente) === [];
    }

    /** @return list<array{0: string, 1: ?string, 2: string, 3: string}> rótulo, valor, aba, gênero do rótulo */
    private function camposObrigatorios(Cliente $cliente): array
    {
        $endereco = [
            ['Logradouro', $cliente->getEndereco(), 'endereco', 'o'],
            ['Número', $cliente->getNumero(), 'endereco', 'o'],
            ['Bairro', $cliente->getBairro(), 'endereco', 'o'],
            ['Cidade', $cliente->getCidade(), 'endereco', 'a'],
            ['UF', $cliente->getEstado(), 'endereco', 'a'],
            ['CEP', $cliente->getCep(), 'endereco', 'o'],
        ];

        if ($cliente instanceof ClientePF) {
            return [
                ['Nome completo', $cliente->getNomeCompleto(), 'pessoais', 'o'],
                ['Estado civil', $cliente->getEstadoCivil(), 'pessoais', 'o'],
                ['Profissão', $cliente->getProfissao(), 'pessoais', 'a'],
                ['RG', $cliente->getRg(), 'pessoais', 'o'],
                ['CPF', $cliente->getCpf(), 'pessoais', 'o'],
                ...$endereco,
            ];
        }

        if ($cliente instanceof ClientePJ) {
            return [
                ['Razão social', $cliente->getRazaoSocial(), 'pessoais', 'a'],
                ['CNPJ', $cliente->getCnpj(), 'pessoais', 'o'],
                ['Representante legal', $cliente->getRepresentanteLegal(), 'pessoais', 'o'],
                ['CPF do representante', $cliente->getRepresentanteCpf(), 'pessoais', 'o'],
                ...$endereco,
            ];
        }

        return $endereco;
    }

    /** @param list<ClienteDocumento> $documentos */
    private static function temAnexo(array $documentos, string $categoria): bool
    {
        foreach ($documentos as $documento) {
            if ($documento->getCategoria() === $categoria && $documento->estaDisponivel()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ClienteDocumento> */
    private function documentosQueContam(Cliente $cliente): array
    {
        $usuario = $this->seguranca?->getUser();
        if ($this->visiveis === null || !$usuario instanceof User) {
            return self::soOsAnexosProprios($cliente);
        }

        return $this->visiveis->de($cliente, $usuario, $this->tenants?->getCurrentTenant());
    }

    /** @return list<ClienteDocumento> */
    private static function soOsAnexosProprios(Cliente $cliente): array
    {
        return array_values(array_filter($cliente->getDocumentos()->toArray(), static fn (ClienteDocumento $d): bool => !$d->isVinculado()));
    }

    private static function vazio(?string $valor): bool
    {
        return $valor === null || trim($valor) === '';
    }
}
