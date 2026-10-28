<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\DigitoVerificador;
use App\Tenant\DTO\SalvarDadosDoEscritorioInput;
use App\Tenant\Exception\AcessoAoEscritorioNegado;
use App\Tenant\Exception\DadosDoEscritorioInvalidos;
use App\Tenant\Service\EnderecoDoEscritorio;
use App\Tenant\Service\GuardaDaEdicaoDoEscritorio;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * "Salvar" do modo edição da tela Meu Escritório (spec meu-escritorio.md). Só o primário ou o Master (D1, no servidor).
 * O escritório é o que o controller validou na rota, nunca um campo do formulário. Limpa (apara, tira HTML), valida com
 * as frases do desenho, grava e carimba "alterado por/em" quando algo mudou. Vazio vira null. O `flush` fica na mesma
 * chamada: a trilha do `AuditLogSubscriber` registra o diff do nome, CNPJ, OAB, e-mail, telefone e endereço.
 */
final class SalvarDadosDoEscritorioUseCase
{
    public const NOME_VAZIO = 'Informe o nome do escritório.';
    public const DOC_TAMANHO = 'CNPJ precisa ter 14 números (ou CPF, 11).';
    public const CNPJ_INVALIDO = 'CNPJ inválido: confira os dígitos.';
    public const CPF_INVALIDO = 'CPF inválido: confira os dígitos.';
    public const EMAIL_INVALIDO = 'E-mail inválido.';
    public const TELEFONE_INVALIDO = 'Telefone inválido: informe o DDD e o número.';
    public const CEP_INVALIDO = 'CEP inválido: informe 8 números.';
    public const UF_INVALIDA = 'UF inválida: use duas letras (ex.: Belo Horizonte/MG).';

    public function __construct(
        private readonly GuardaDaEdicaoDoEscritorio $guarda,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @throws AcessoAoEscritorioNegado
     * @throws DadosDoEscritorioInvalidos
     */
    public function executar(User $ator, Tenant $tenant, SalvarDadosDoEscritorioInput $input): void
    {
        $this->guarda->exigir($ator, $tenant);
        $valores = $this->validar($input);

        $antes = $this->retrato($tenant);
        $this->aplicar($tenant, $valores);
        if ($this->retrato($tenant) !== $antes) {
            $tenant->carimbarAlteracao($ator, \DateTimeImmutable::createFromInterface($this->relogio->now()));
        }
        $this->em->flush();
    }

    /**
     * Limpa e valida tudo antes de mexer na entidade: uma recusa não deixa nada pela metade.
     *
     * @return array<string, string|null>
     *
     * @throws DadosDoEscritorioInvalidos
     */
    private function validar(SalvarDadosDoEscritorioInput $input): array
    {
        $nome = $this->limpar($input->nome);
        if ($nome === '') {
            throw new DadosDoEscritorioInvalidos(self::NOME_VAZIO, 'nome');
        }
        $this->limitar($nome, 255, 'O nome');
        $doc = $this->documento($input->doc);
        $oab = $this->limpar($input->oab);
        $this->limitar($oab, 40, 'O registro na OAB');
        $endereco = $this->limpar($input->endereco);
        $this->limitar($endereco, 255, 'O endereço');
        $email = mb_strtolower($this->limpar($input->email));
        if ($email !== '' && (mb_strlen($email) > 180 || filter_var($email, \FILTER_VALIDATE_EMAIL) === false)) {
            throw new DadosDoEscritorioInvalidos(self::EMAIL_INVALIDO, 'email');
        }
        $telefone = $this->telefone($input->telefone);
        $cep = $this->cep($input->cep);
        [$cidade, $estado] = $this->cidadeEUf($input->cidadeUf);

        return ['nome' => $nome, 'doc' => $doc, 'oab' => $oab === '' ? null : $oab, 'email' => $email === '' ? null : $email, 'telefone' => $telefone, 'cep' => $cep, 'endereco' => $endereco, 'cidade' => $cidade, 'estado' => $estado];
    }

    /** @param array<string, string|null> $v */
    private function aplicar(Tenant $tenant, array $v): void
    {
        $tenant->setName((string) $v['nome'])
            ->setCnpj($v['doc'])
            ->setOab($v['oab'])
            ->setEmailContato($v['email'])
            ->setTelefone($v['telefone'])
            ->setCep($v['cep'])
            ->setCidade($v['cidade'])
            ->setEstado($v['estado']);
        // O desenho tem um campo só de endereço. Só se o texto mudou em relação ao exibido ele vira o logradouro e zera
        // número, complemento e bairro; quem não mexe no endereço não perde nada.
        $endereco = (string) $v['endereco'];
        if ($endereco !== (EnderecoDoEscritorio::linha($tenant) ?? '')) {
            $tenant->setLogradouro($endereco === '' ? null : $endereco)->setNumero(null)->setComplemento(null)->setBairro(null);
        }
    }

    /** @return list<string|null> */
    private function retrato(Tenant $t): array
    {
        return [$t->getName(), $t->getCnpj(), $t->getOab(), $t->getEmailContato(), $t->getTelefone(), $t->getCep(), $t->getLogradouro(), $t->getNumero(), $t->getComplemento(), $t->getBairro(), $t->getCidade(), $t->getEstado()];
    }

    private function limpar(string $valor): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($valor)) ?? '');
    }

    private function limitar(string $valor, int $max, string $rotulo): void
    {
        if (mb_strlen($valor) > $max) {
            throw new DadosDoEscritorioInvalidos(sprintf('%s passa de %d caracteres.', $rotulo, $max), '');
        }
    }

    private function documento(string $bruto): ?string
    {
        $digitos = DigitoVerificador::digitos($this->limpar($bruto));
        if ($digitos === '') {
            return null;
        }
        if (\strlen($digitos) !== 11 && \strlen($digitos) !== 14) {
            throw new DadosDoEscritorioInvalidos(self::DOC_TAMANHO, 'doc');
        }
        if (\strlen($digitos) === 14 && !DigitoVerificador::cnpjValido($digitos)) {
            throw new DadosDoEscritorioInvalidos(self::CNPJ_INVALIDO, 'doc');
        }
        if (\strlen($digitos) === 11 && !DigitoVerificador::cpfValido($digitos)) {
            throw new DadosDoEscritorioInvalidos(self::CPF_INVALIDO, 'doc');
        }

        return $digitos;
    }

    private function telefone(string $bruto): ?string
    {
        $telefone = $this->limpar($bruto);
        if ($telefone === '') {
            return null;
        }
        $digitos = DigitoVerificador::digitos($telefone);
        if (preg_match('/^[0-9()+\-\s.]+$/', $telefone) !== 1 || \strlen($digitos) < 8 || \strlen($digitos) > 13 || mb_strlen($telefone) > 20) {
            throw new DadosDoEscritorioInvalidos(self::TELEFONE_INVALIDO, 'telefone');
        }

        return $telefone;
    }

    private function cep(string $bruto): ?string
    {
        $digitos = DigitoVerificador::digitos($this->limpar($bruto));
        if ($digitos === '') {
            return null;
        }
        if (\strlen($digitos) !== 8) {
            throw new DadosDoEscritorioInvalidos(self::CEP_INVALIDO, 'cep');
        }

        return substr($digitos, 0, 5) . '-' . substr($digitos, 5);
    }

    /** @return array{0: string|null, 1: string|null} */
    private function cidadeEUf(string $bruto): array
    {
        $texto = $this->limpar($bruto);
        if ($texto === '') {
            return [null, null];
        }
        $barra = strrpos($texto, '/');
        $cidade = $barra === false ? $texto : trim(substr($texto, 0, $barra));
        $uf = $barra === false ? '' : mb_strtoupper(trim(substr($texto, $barra + 1)));
        if ($uf !== '' && preg_match('/^[A-Z]{2}$/', $uf) !== 1) {
            throw new DadosDoEscritorioInvalidos(self::UF_INVALIDA, 'cidadeUf');
        }
        $this->limitar($cidade, 100, 'A cidade');

        return [$cidade ?: null, $uf ?: null];
    }
}
