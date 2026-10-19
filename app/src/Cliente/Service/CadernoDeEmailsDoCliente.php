<?php

declare(strict_types=1);

namespace App\Cliente\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteEmail;
use App\Cliente\Enum\RotuloDoEmail;
use App\Cliente\Exception\EmailDoClienteNaoEncontradoException;
use App\Cliente\Exception\EmailDoClienteRecusadoException;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * As regras da lista de e-mails de UM cliente (spec push-avisar-cliente §1; desenho `mailReg` e
 * `mailVals`, dc L.5228-5293). Puro: recebe as linhas já lidas e devolve o que gravar e o que apagar.
 *
 *   - no máximo {@see self::LIMITE} e-mails visíveis; um só principal; endereço normalizado
 *     (minúsculo, sem espaço nas pontas) e validado como o desenho (`MAIL_RE`) e como o envio exige;
 *   - sem linha nenhuma, o e-mail válido da ficha (`Cliente.email`) aparece como item virtual
 *     "Cadastro · principal" (id {@see self::ID_DO_CADASTRO}); a primeira alteração o materializa;
 *   - o primeiro cadastrado vira principal; removido o principal, o primeiro que sobra assume;
 *   - a linha `do_cadastro` removida fica marcada (ver {@see ClienteEmail}), mesmo restaurada com
 *     outro rótulo; as outras saem. Havendo qualquer linha, o item virtual não volta.
 */
final class CadernoDeEmailsDoCliente
{
    public const LIMITE = 5;

    /** O id do item virtual do cadastro, enquanto ele não vira linha. */
    public const ID_DO_CADASTRO = 0;

    public const ERRO_INVALIDO = 'Digite um e-mail válido.';
    public const ERRO_REPETIDO = 'Este e-mail já está cadastrado.';
    public const ERRO_LIMITE = 'Limite de 5 e-mails por cliente.';

    /** O `MAIL_RE` do desenho (dc L.5235). */
    private const PADRAO = '/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i';

    private const TAMANHO_MAXIMO = 180;

    /** @var list<ClienteEmail> */
    private array $novas = [];

    /** @var list<ClienteEmail> */
    private array $apagadas = [];

    /**
     * @param list<ClienteEmail> $linhas TODAS as linhas do cliente, em ordem de criação
     */
    public function __construct(
        private readonly Tenant $tenant,
        private readonly Cliente $cliente,
        private array $linhas,
    ) {
    }

    public static function normalizar(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function ehValido(string $email): bool
    {
        return \strlen($email) <= self::TAMANHO_MAXIMO
            && preg_match(self::PADRAO, $email) === 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Os e-mails como a tela os mostra: as linhas não removidas ou, sem linha nenhuma, o item virtual
     * do cadastro (id nulo, não persistido).
     *
     * @return list<ClienteEmail>
     */
    public function itens(): array
    {
        if ($this->linhas === []) {
            $virtual = $this->itemDoCadastro(null, $this->cliente->getCriadoAt() ?? new \DateTimeImmutable());

            return $virtual !== null ? [$virtual] : [];
        }

        return array_values(array_filter($this->linhas, static fn (ClienteEmail $e): bool => !$e->estaRemovido()));
    }

    public function contem(string $email): bool
    {
        $email = self::normalizar($email);
        foreach ($this->itens() as $item) {
            if ($item->getEmail() === $email) {
                return true;
            }
        }

        return false;
    }

    /** Cadastra (validações na ordem do desenho) e devolve o endereço normalizado. */
    public function adicionar(string $email, RotuloDoEmail $rotulo, User $por, \DateTimeImmutable $em): string
    {
        $email = self::normalizar($email);
        $this->exigirNovoEValido($email);
        $this->materializarCadastro($por, $em);
        $primeiro = $this->itens() === [];

        $removida = $this->linhaDe($email);
        if ($removida !== null) {
            $removida->restaurar($rotulo, $primeiro, $por, $em);

            return $email;
        }

        $nova = new ClienteEmail($this->tenant, $this->cliente, $email, $rotulo, $primeiro, $por, $em);
        $this->linhas[] = $nova;
        $this->novas[] = $nova;

        return $email;
    }

    /** O item pelo id da tela (o do cadastro virtual é materializado aqui). */
    public function item(int $id, User $por, \DateTimeImmutable $em): ClienteEmail
    {
        if ($id === self::ID_DO_CADASTRO) {
            $this->exigirVirtual();
            $this->materializarCadastro($por, $em);

            return $this->itens()[0];
        }

        foreach ($this->itens() as $item) {
            if ($item->getId() === $id) {
                return $item;
            }
        }

        throw new EmailDoClienteNaoEncontradoException('E-mail não encontrado.');
    }

    public function tornarPrincipal(ClienteEmail $alvo): void
    {
        foreach ($this->itens() as $item) {
            $item->tornarPrincipal($item === $alvo);
        }
    }

    public function remover(ClienteEmail $alvo, \DateTimeImmutable $em): void
    {
        $eraPrincipal = $alvo->ehPrincipal();
        if ($alvo->ehDoCadastro()) {
            $alvo->marcarRemovido($em);
        } else {
            $this->linhas = array_values(array_filter($this->linhas, static fn (ClienteEmail $e): bool => $e !== $alvo));
            $this->novas = array_values(array_filter($this->novas, static fn (ClienteEmail $e): bool => $e !== $alvo));
            $this->apagadas[] = $alvo;
        }

        $restantes = $this->itens();
        if ($eraPrincipal && $restantes !== []) {
            $restantes[0]->tornarPrincipal(true);
        }
    }

    /** @return list<ClienteEmail> as linhas criadas nesta operação (a gravar) */
    public function novas(): array
    {
        return $this->novas;
    }

    /** O principal visível (com ou sem id), se houver. */
    public function principal(): ?ClienteEmail
    {
        foreach ($this->itens() as $item) {
            if ($item->ehPrincipal()) {
                return $item;
            }
        }

        return null;
    }

    /** @return list<ClienteEmail> as linhas a apagar */
    public function apagadas(): array
    {
        return array_values(array_filter($this->apagadas, static fn (ClienteEmail $e): bool => $e->getId() !== null));
    }

    private function exigirNovoEValido(string $email): void
    {
        if (!self::ehValido($email)) {
            throw new EmailDoClienteRecusadoException(self::ERRO_INVALIDO);
        }
        if ($this->contem($email)) {
            throw new EmailDoClienteRecusadoException(self::ERRO_REPETIDO);
        }
        if (\count($this->itens()) >= self::LIMITE) {
            throw new EmailDoClienteRecusadoException(self::ERRO_LIMITE);
        }
    }

    private function exigirVirtual(): void
    {
        if ($this->linhas !== [] || $this->itens() === []) {
            throw new EmailDoClienteNaoEncontradoException('E-mail não encontrado.');
        }
    }

    /** Sem linha nenhuma, o e-mail da ficha vira linha (a "primeira alteração" do desenho). */
    private function materializarCadastro(User $por, \DateTimeImmutable $em): void
    {
        if ($this->linhas !== []) {
            return;
        }

        $cadastro = $this->itemDoCadastro($por, $em);
        if ($cadastro !== null) {
            $this->linhas[] = $cadastro;
            $this->novas[] = $cadastro;
        }
    }

    private function itemDoCadastro(?User $por, \DateTimeImmutable $em): ?ClienteEmail
    {
        $email = self::normalizar($this->cliente->getEmail());
        if (!self::ehValido($email)) {
            return null;
        }

        return new ClienteEmail($this->tenant, $this->cliente, $email, RotuloDoEmail::Cadastro, true, $por, $em, doCadastro: true);
    }

    private function linhaDe(string $email): ?ClienteEmail
    {
        foreach ($this->linhas as $linha) {
            if ($linha->getEmail() === $email) {
                return $linha;
            }
        }

        return null;
    }
}
