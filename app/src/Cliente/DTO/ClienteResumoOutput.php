<?php

declare(strict_types=1);

namespace App\Cliente\DTO;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\UseCase\AtualizarContatoDoClienteUseCase as Contato;
use App\Pasta\Entity\Pasta;
use App\Twig\DocumentoBrExtension;

/**
 * Tudo que a janela "Detalhes do cliente" (desenho 1.2.3, dc L.779-836) imprime,
 * já resolvido: a tela não decide situação de pasta nem formata documento.
 *
 * Contatos: os TRÊS slots fixos do cadastro (celular, fixo, e-mail), cada um com
 * o `campo` que a edição inline manda (`cliente_contatos`). O desenho tem lista
 * livre de contatos; o modelo, não — "+ Telefone" só existe enquanto houver slot
 * de telefone vazio (`telefoneLivre`) e "+ E-mail" só se o e-mail estiver vazio.
 *
 * As pastas chegam JÁ FILTRADAS pela permissão por pasta (quem monta é o
 * controller): este DTO não sabe nada de permissão e não pode ser o lugar onde
 * uma pasta proibida escapa.
 */
final readonly class ClienteResumoOutput
{
    public const TAG_ATUAL     = 'atual';
    public const TAG_ATIVA     = 'ativa';
    public const TAG_ARQUIVADA = 'arquivada';

    /**
     * @param list<array{campo: string, tipo: string, icone: string, rotulo: string, valor: string}> $contatos
     * @param list<array{id: int, nup: string, acao: string, tag: string}>       $pastas
     * @param list<string>                                                      $pendencias
     */
    public function __construct(
        public int $clienteId,
        public string $nome,
        public string $documentoRotulo,
        public string $documento,
        public ?\DateTimeImmutable $clienteDesde,
        public array $contatos,
        public array $pastas,
        public int $totalPastas,
        public int $pastasAtivas,
        public int $pastasComProcesso,
        public array $pendencias,
        public string $qualificacao,
        public bool $podeEditar,
        public ?string $telefoneLivre = null,
        public bool $emailVazio = false,
    ) {}

    /**
     * @param list<Pasta>  $pastasVisiveis só as que o usuário pode abrir
     * @param list<string> $pendencias
     */
    public static function montar(
        Cliente $cliente,
        array $pastasVisiveis,
        ?int $pastaAtualId,
        array $pendencias,
        string $qualificacao,
        bool $podeEditar,
    ): self {
        $doc       = new DocumentoBrExtension();
        $documento = match (true) {
            $cliente instanceof ClientePF => $cliente->getCpf(),
            $cliente instanceof ClientePJ => $cliente->getCnpj(),
            default                       => '',
        };

        usort(
            $pastasVisiveis,
            static fn (Pasta $a, Pasta $b): int => strnatcmp((string) $a->getNup(), (string) $b->getNup()),
        );

        $pastas      = [];
        $ativas      = 0;
        $comProcesso = 0;
        foreach ($pastasVisiveis as $pasta) {
            $arquivada = $pasta->getSituacao() === Pasta::SITUACAO_ARQUIVADA;
            if (!$arquivada) {
                ++$ativas;
            }
            if ($pasta->getPastaProcessos()->count() > 0) {
                ++$comProcesso;
            }

            $tag = match (true) {
                $pastaAtualId !== null && $pasta->getId() === $pastaAtualId => self::TAG_ATUAL,
                $arquivada                                                   => self::TAG_ARQUIVADA,
                default                                                      => self::TAG_ATIVA,
            };

            $acao     = trim((string) $pasta->getNomeAcao());
            $pastas[] = [
                'id'   => (int) $pasta->getId(),
                'nup'  => (string) $pasta->getNup(),
                'acao' => $acao !== '' ? $acao : 'Ação não informada',
                'tag'  => $tag,
            ];
        }

        return new self(
            clienteId: (int) $cliente->getId(),
            nome: $cliente->getNomeExibicao(),
            documentoRotulo: $doc->documentoBrRotulo($documento),
            documento: $doc->documentoBr($documento),
            clienteDesde: $cliente->getCriadoAt(),
            contatos: self::contatos($cliente),
            pastas: $pastas,
            totalPastas: \count($pastas),
            pastasAtivas: $ativas,
            pastasComProcesso: $comProcesso,
            pendencias: $pendencias,
            qualificacao: $qualificacao,
            podeEditar: $podeEditar,
            telefoneLivre: self::telefoneLivre($cliente),
            emailVazio: trim($cliente->getEmail()) === '',
        );
    }

    /** Slot que "+ Telefone" preenche: o celular primeiro, depois o fixo; nulo se os dois têm valor. */
    private static function telefoneLivre(Cliente $cliente): ?string
    {
        if (trim((string) $cliente->getTelefoneCelular()) === '') {
            return Contato::CAMPO_CELULAR;
        }
        if (trim((string) $cliente->getTelefoneFixo()) === '') {
            return Contato::CAMPO_FIXO;
        }

        return null;
    }

    /** @return list<array{campo: string, tipo: string, icone: string, rotulo: string, valor: string}> */
    private static function contatos(Cliente $cliente): array
    {
        $contatos = [];

        $celular = trim((string) $cliente->getTelefoneCelular());
        if ($celular !== '') {
            $contatos[] = ['campo' => Contato::CAMPO_CELULAR, 'tipo' => 'tel', 'icone' => 'bi-telephone', 'rotulo' => 'Celular', 'valor' => $celular];
        }

        // O fixo aparece mesmo quando repete o celular: cada linha é um slot editável do
        // cadastro, e esconder a repetida deixava o fixo sem lápis nem lixeira — e sem
        // "+ Telefone", porque o slot não está vazio.
        $fixo = trim((string) $cliente->getTelefoneFixo());
        if ($fixo !== '') {
            $contatos[] = ['campo' => Contato::CAMPO_FIXO, 'tipo' => 'tel', 'icone' => 'bi-telephone', 'rotulo' => 'Telefone fixo', 'valor' => $fixo];
        }

        $email = trim($cliente->getEmail());
        if ($email !== '') {
            $contatos[] = ['campo' => Contato::CAMPO_EMAIL, 'tipo' => 'email', 'icone' => 'bi-envelope', 'rotulo' => 'E-mail', 'valor' => $email];
        }

        return $contatos;
    }
}
