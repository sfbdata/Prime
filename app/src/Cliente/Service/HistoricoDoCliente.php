<?php

declare(strict_types=1);

namespace App\Cliente\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClienteHistorico;
use App\Cliente\Repository\ClienteHistoricoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Escreve e lê a aba "Histórico" da janela de Cadastro (desenho 1.2.3, `cadSalvar` → `hist`; E12 fatia 3d).
 *
 * Os textos seguem o desenho: "<quem> criou o cadastro", "<quem> alterou nome, CPF, estado civil". Só RÓTULOS, nunca o
 * valor do campo (LGPD: CPF, RG e endereço não são copiados para o histórico); um rótulo fora de
 * {@see CamposDoCadastro::ROTULOS} é descartado, para nenhum valor entrar por engano. Documento anexado ou removido
 * entra pelo tipo ("documento de identificação"), sem o nome do arquivo, que pode trazer nome ou CPF da pessoa.
 *
 * Só persiste: quem chama dá o flush junto da própria mudança, na mesma transação.
 */
final class HistoricoDoCliente
{
    /** Quantas linhas a aba mostra (o protótipo guarda 40; o sistema guarda todas e mostra as 40 mais novas). */
    public const LIMITE_NA_TELA = 40;

    /** Tipo do documento => como ele aparece na linha (minúsculo, como o desenho põe no "alterou"). */
    private const DOCUMENTOS = [
        ClienteDocumento::CATEGORIA_IDENTIFICACAO          => 'documento de identificação',
        ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => 'comprovante de residência',
        ClienteDocumento::CATEGORIA_PROCURACAO             => 'procuração',
        ClienteDocumento::CATEGORIA_CONTRATO               => 'contrato',
        ClienteDocumento::CATEGORIA_DEMAIS                 => 'documento',
    ];

    public function __construct(
        private readonly ClienteHistoricoRepository $repositorio,
    ) {
    }

    public function cadastroCriado(Cliente $cliente, User $autor, Tenant $tenant): void
    {
        $this->gravar($cliente, $autor, $tenant, self::nomeDe($autor) . ' criou o cadastro');
    }

    /**
     * @param list<string> $rotulos rótulos de {@see CamposDoCadastro::ROTULOS} (ou de ROTULOS_FORA_DA_JANELA); vazio (ou
     *                              só desconhecidos) não grava
     */
    public function camposAlterados(Cliente $cliente, User $autor, Tenant $tenant, array $rotulos): void
    {
        $texto = self::textoDeAlteracao(self::nomeDe($autor), $rotulos);
        if ($texto !== null) {
            $this->gravar($cliente, $autor, $tenant, $texto);
        }
    }

    /** O documento já ligado ao cliente e ao escritório dele (como a rota de envio monta). */
    public function documentoAnexado(ClienteDocumento $documento, User $autor): void
    {
        $this->gravarDoDocumento($documento, $autor, 'anexou');
    }

    /** Metadados do documento mudaram (tipo, descrição, número, nome): entra pelo tipo que ele tinha antes. */
    public function documentoAlterado(ClienteDocumento $documento, User $autor, string $categoriaAnterior): void
    {
        $this->gravarDoDocumento($documento, $autor, 'alterou', $categoriaAnterior);
    }

    /** Chame ANTES de remover: depois do flush o documento não diz mais de quem era. */
    public function documentoRemovido(ClienteDocumento $documento, User $autor): void
    {
        $this->gravarDoDocumento($documento, $autor, 'removeu');
    }

    /**
     * A lista da aba, já pronta para a tela: mais nova primeiro, `q` = "dd/mm/aaaa hh:mm" no fuso da aplicação.
     *
     * @return list<array{q: string, t: string}>
     */
    public function paraTela(Cliente $cliente, Tenant $tenant): array
    {
        if ($cliente->getId() === null || $cliente->getTenant()?->getId() !== $tenant->getId()) {
            return [];
        }

        return array_map(
            static fn (ClienteHistorico $h): array => ['q' => $h->getEm()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m/Y H:i'), 't' => $h->getTexto()],
            $this->repositorio->ultimosDoCliente($cliente, $tenant, self::LIMITE_NA_TELA),
        );
    }

    /** @param list<string> $rotulos */
    public static function textoDeAlteracao(string $autor, array $rotulos): ?string
    {
        $conhecidos = array_merge(array_values(CamposDoCadastro::ROTULOS), array_values(CamposDoCadastro::ROTULOS_FORA_DA_JANELA));
        $validos = array_values(array_unique(array_intersect($rotulos, $conhecidos)));

        return $validos === [] ? null : $autor . ' alterou ' . implode(', ', $validos);
    }

    private function gravar(Cliente $cliente, User $autor, Tenant $tenant, string $texto): void
    {
        if ($cliente->getTenant()?->getId() !== $tenant->getId()) {
            throw new \DomainException('Histórico de cliente de outro escritório.');
        }
        $this->repositorio->registrar(new ClienteHistorico($tenant, $cliente, $autor, $texto));
    }

    private function gravarDoDocumento(ClienteDocumento $documento, User $autor, string $verbo, ?string $categoria = null): void
    {
        $cliente = $documento->getCliente();
        $tenant = $documento->getTenant();
        if ($cliente === null || $tenant === null) {
            throw new \DomainException('Documento sem cliente ou sem escritório.');
        }
        $this->gravar($cliente, $autor, $tenant, self::nomeDe($autor) . ' ' . $verbo . ' ' . self::documento($categoria ?? $documento->getCategoria()));
    }

    private static function nomeDe(User $autor): string
    {
        $nome = trim((string) $autor->getFullName());

        return $nome !== '' ? $nome : 'Usuário';
    }

    private static function documento(string $categoria): string
    {
        return self::DOCUMENTOS[$categoria] ?? 'documento';
    }
}
