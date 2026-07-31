<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

use App\Cobranca\Entity\Pessoa;

/**
 * O que UMA pessoa já tem durante UMA execução do importador de cadastro — somando o que veio do banco
 * com o que as linhas anteriores do MESMO arquivo já lhe deram.
 *
 * Existe por um motivo só: fazer a prévia e a confirmação percorrerem o mesmo caminho. No dry-run não há
 * entidade persistida para consultar, então a projeção só conseguia olhar o banco; foi essa cegueira que
 * fez a prévia prometer 215 vínculos onde a confirmação abriu 242, e 292 telefones onde ela acrescentou
 * 290 (medido na planilha real em 31/07). Com o estado acumulado aqui, as duas passam a fazer a MESMA
 * pergunta — "isto já existe?" — e a resposta não depende de ter havido escrita.
 *
 * A comparação de cada tipo de contato é a mesma que o domínio usa: telefone por dígitos, e-mail sem
 * caixa, endereço por logradouro/número/complemento/CEP.
 */
final class PessoaEmImportacao
{
    /** @var list<string> dígitos dos telefones já presentes */
    private array $telefones = [];

    /** @var list<string> e-mails em minúsculas */
    private array $emails = [];

    /** @var list<string> chaves de endereço */
    private array $enderecos = [];

    /** @var list<string> identificações de unidade em que a pessoa já tem vínculo aberto nesta execução */
    private array $unidades = [];

    private function __construct(
        private ?Pessoa $pessoa,
        private readonly bool $nasceuNestaImportacao,
    ) {
    }

    /** Pessoa que já estava cadastrada: o estado começa com o que ela tem no banco. */
    public static function existente(Pessoa $pessoa): self
    {
        $estado = new self($pessoa, false);

        foreach ($pessoa->getTelefones() as $telefone) {
            $digitos = self::digitos((string) $telefone->getNumero());
            if ($digitos !== '') {
                $estado->telefones[] = $digitos;
            }
        }
        foreach ($pessoa->getEmails() as $email) {
            $estado->emails[] = mb_strtolower((string) $email->getEmail());
        }
        foreach ($pessoa->getEnderecos() as $endereco) {
            $estado->enderecos[] = self::chaveEndereco([
                'logradouro' => (string) $endereco->getLogradouro(),
                'numero' => (string) $endereco->getNumero(),
                'complemento' => (string) $endereco->getComplemento(),
                'cep' => (string) $endereco->getCep(),
            ]);
        }

        return $estado;
    }

    /** Pessoa que a importação vai criar. No dry-run ela nunca ganha entidade — e não precisa. */
    public static function nova(): self
    {
        return new self(null, true);
    }

    /** A entidade, quando existe: null no dry-run de pessoa nova (nada foi persistido). */
    public function pessoa(): ?Pessoa
    {
        return $this->pessoa;
    }

    public function definirPessoa(Pessoa $pessoa): void
    {
        $this->pessoa = $pessoa;
    }

    public function nasceuNestaImportacao(): bool
    {
        return $this->nasceuNestaImportacao;
    }

    /**
     * Telefones do condômino que a pessoa ainda NÃO tem, sem repetir — o próprio relatório traz o mesmo
     * número duas vezes na mesma célula, e contá-lo duas vezes era metade da divergência de 31/07.
     *
     * @return list<string> na forma original da planilha (é ela que vai para o cadastro)
     */
    public function telefonesAusentes(CondominoImportavel $condomino): array
    {
        $ausentes = [];
        $vistos = $this->telefones;

        foreach ($condomino->telefones as $numero) {
            $digitos = self::digitos($numero);
            if ($digitos !== '' && !in_array($digitos, $vistos, true)) {
                $ausentes[] = $numero;
                $vistos[] = $digitos;
            }
        }

        return $ausentes;
    }

    public function emailAusente(CondominoImportavel $condomino): bool
    {
        return $condomino->email !== null && !in_array(mb_strtolower($condomino->email), $this->emails, true);
    }

    public function enderecoAusente(CondominoImportavel $condomino): bool
    {
        return $condomino->temEndereco() && !in_array(self::chaveEndereco($condomino->endereco), $this->enderecos, true);
    }

    public function temVinculoAberto(string $unidade): bool
    {
        return in_array($unidade, $this->unidades, true);
    }

    public function registrarVinculo(string $unidade): void
    {
        $this->unidades[] = $unidade;
    }

    public function registrarTelefone(string $numero): void
    {
        $digitos = self::digitos($numero);
        if ($digitos !== '') {
            $this->telefones[] = $digitos;
        }
    }

    public function registrarEmail(string $email): void
    {
        $this->emails[] = mb_strtolower($email);
    }

    /** @param array<string, string> $endereco */
    public function registrarEndereco(array $endereco): void
    {
        $this->enderecos[] = self::chaveEndereco($endereco);
    }

    /** @param array<string, string> $endereco */
    private static function chaveEndereco(array $endereco): string
    {
        $partes = [
            $endereco['logradouro'] ?? '',
            $endereco['numero'] ?? '',
            $endereco['complemento'] ?? '',
            self::digitos($endereco['cep'] ?? ''),
        ];

        return mb_strtolower(trim(implode('|', array_map('trim', $partes))));
    }

    private static function digitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }
}
