<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Backup\Service\ValoresDoBackup;
use App\Backup\Service\ZipDoBackup;
use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClienteEmail;
use App\Cliente\Entity\ClienteHistorico;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\EscopoDePastas;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Clientes no backup: `dados/clientes.json` (cadastro PF/PJ, e-mails e histórico) e
 * `clientes/<nome>/documentos/<arquivo>` (documentos e foto).
 *
 * Só roda para quem tem o módulo de clientes. Clientes = os do escritório (`tenant_id` escrito na mão); no acesso
 * restrito, só os do escopo ({@see EscopoDePastas::idsDosClientesNoEscopo()}). O documento vinculado a documento de pasta
 * entra só se a pasta e o documento forem visíveis para a pessoa (ver {@see vinculadosVisiveis()}); cada cliente também
 * exige `resources.cliente.view`, como a tela.
 */
#[AsTaggedItem(priority: 10)]
final class ColetorDeClientes implements ColetorDoBackup
{
    public const MODULO = 'clientes';

    public const CLIENTES_POR_LOTE = 50;

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly EscopoDePastas $escopo,
        private readonly AcessoAPasta $acesso,
        private readonly VisibilidadeDasAbasDaPasta $abas,
        private readonly EntityManagerInterface $em,
        private readonly int $clientesPorLote = self::CLIENTES_POR_LOTE,
    ) {
    }

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
    {
        if (!$this->permissoes->canAccessModule($usuario, $tenant, self::MODULO)) {
            return;
        }

        $ocultas     = $this->acesso->idsDasPastasOcultas($usuario, $tenant);
        $abasOcultas = $this->abas->abasOcultasPorPasta($usuario, $tenant);
        $usuarioId   = (int) $usuario->getId();
        $tenantId    = (int) $tenant->getId();
        $dados       = [];
        $raiz        = null;
        $lidos       = 0;
        foreach ($this->clientesVisiveis($usuario, $tenant) as $cliente) {
            if (!$this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, (int) $cliente->getId(), AccessRequest::ACTION_VIEW)) {
                continue;
            }
            $raiz ??= $zip->reservarDiretorio('', 'clientes');
            $dir   = $zip->reservarDiretorio($raiz, $cliente->getNomeExibicao());
            $dados[] = $this->dadosDoCliente($cliente, $tenant);
            $this->coletarArquivos($cliente, $usuario, $tenant, $ocultas, $abasOcultas, $zip, $zip->reservarDiretorio($dir, 'documentos'));
            $zip->contar('Clientes');

            if (++$lidos % $this->clientesPorLote === 0) {
                // Memória do worker: solta o lote e recarrega só a pessoa e o escritório.
                $this->em->clear();
                $usuario = $this->em->find(User::class, $usuarioId) ?? throw new \LogicException('Usuário do backup sumiu.');
                $tenant  = $this->em->find(Tenant::class, $tenantId) ?? throw new \LogicException('Escritório do backup sumiu.');
            }
        }

        $dir = $zip->reservarDiretorio('', 'dados');
        $zip->adicionarJson($dir . '/clientes.json', $dados);
    }

    /** @return iterable<Cliente> */
    private function clientesVisiveis(User $usuario, Tenant $tenant): iterable
    {
        $escopo = $this->escopo->idsDosClientesNoEscopo($usuario, $tenant);
        if ($escopo === []) {
            return;
        }

        $qb = $this->em->createQueryBuilder()
            ->select('c')->from(Cliente::class, 'c')
            ->andWhere('c.tenant = :tenant')->setParameter('tenant', $tenant)
            ->orderBy('c.id');
        if ($escopo !== null) {
            $qb->andWhere('c.id IN (:escopo)')->setParameter('escopo', $escopo);
        }

        yield from $qb->getQuery()->toIterable();
    }

    /** @return array<string, mixed> */
    private function dadosDoCliente(Cliente $cliente, Tenant $tenant): array
    {
        return [
            'tipo'     => $cliente instanceof ClientePJ ? 'pj' : 'pf',
            'nome'     => $cliente->getNomeExibicao(),
            ...$this->cadastro($cliente),
            'contato'  => [
                'email'            => $cliente->getEmail(),
                'telefone_celular' => $cliente->getTelefoneCelular(),
                'telefone_fixo'    => $cliente->getTelefoneFixo(),
                'whatsapp'         => $cliente->getWhatsapp(),
            ],
            'endereco' => [
                'cep'         => $cliente->getCep(),
                'logradouro'  => $cliente->getEndereco(),
                'numero'      => $cliente->getNumero(),
                'complemento' => $cliente->getComplemento(),
                'bairro'      => $cliente->getBairro(),
                'cidade'      => $cliente->getCidade(),
                'estado'      => $cliente->getEstado(),
            ],
            'criado_em' => ValoresDoBackup::data($cliente->getCriadoAt()),
            'emails'    => $this->emails($cliente, $tenant),
            'historico' => $this->historico($cliente, $tenant),
        ];
    }

    /** @return array<string, mixed> */
    private function cadastro(Cliente $cliente): array
    {
        if ($cliente instanceof ClientePJ) {
            return [
                'razao_social'         => $cliente->getRazaoSocial(),
                'nome_fantasia'        => $cliente->getNomeFantasia(),
                'cnpj'                 => $cliente->getCnpj(),
                'inscricao_estadual'   => $cliente->getInscricaoEstadual(),
                'inscricao_municipal'  => $cliente->getInscricaoMunicipal(),
                'endereco_sede'        => $cliente->getEnderecSede(),
                'representante_legal'  => $cliente->getRepresentanteLegal(),
                'representante_cpf'    => $cliente->getRepresentanteCpf(),
                'representante_rg'     => $cliente->getRepresentanteRg(),
                'representante_cargo'  => $cliente->getRepresentanteCargo(),
            ];
        }
        \assert($cliente instanceof ClientePF);

        return [
            'nome_completo'    => $cliente->getNomeCompleto(),
            'cpf'              => $cliente->getCpf(),
            'rg'               => $cliente->getRg(),
            'rg_orgao'         => $cliente->getRgOrgaoExpedidor(),
            'rg_emissao'       => ValoresDoBackup::dia($cliente->getRgDataEmissao()),
            'data_nascimento'  => ValoresDoBackup::dia($cliente->getDataNascimento()),
            'estado_civil'     => $cliente->getEstadoCivil(),
            'profissao'        => $cliente->getProfissao(),
            'nacionalidade'    => $cliente->getNacionalidade(),
            'genero'           => $cliente->getGenero(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function emails(Cliente $cliente, Tenant $tenant): array
    {
        /** @var list<ClienteEmail> $linhas */
        $linhas = $this->em->createQueryBuilder()
            ->select('e')->from(ClienteEmail::class, 'e')
            ->andWhere('e.cliente = :cliente')->andWhere('e.tenant = :tenant')->andWhere('e.removidoEm IS NULL')
            ->setParameter('cliente', $cliente->getId())->setParameter('tenant', $tenant)
            ->orderBy('e.id')->getQuery()->getResult();

        return array_map(static fn (ClienteEmail $e): array => [
            'email'         => $e->getEmail(),
            'rotulo'        => $e->getRotulo()->value,
            'principal'     => $e->ehPrincipal(),
            'recebe_avisos' => $e->recebeAvisos(),
        ], $linhas);
    }

    /** @return list<array<string, mixed>> */
    private function historico(Cliente $cliente, Tenant $tenant): array
    {
        /** @var list<ClienteHistorico> $linhas */
        $linhas = $this->em->createQueryBuilder()
            ->select('h')->from(ClienteHistorico::class, 'h')
            ->andWhere('h.cliente = :cliente')->andWhere('h.tenant = :tenant')
            ->setParameter('cliente', $cliente->getId())->setParameter('tenant', $tenant)
            ->orderBy('h.em')->addOrderBy('h.id')->getQuery()->getResult();

        return array_map(static fn (ClienteHistorico $h): array => [
            'em'    => ValoresDoBackup::data($h->getEm()),
            'autor' => $h->getAutor()?->getFullName(),
            'texto' => $h->getTexto(),
        ], $linhas);
    }

    /**
     * @param list<int>                      $pastasOcultas
     * @param array<int, list<AbaDaPasta>>   $abasOcultas
     */
    private function coletarArquivos(Cliente $cliente, User $usuario, Tenant $tenant, array $pastasOcultas, array $abasOcultas, ZipDoBackup $zip, string $dir): void
    {
        if ($cliente->getFoto() !== null) {
            $extensao = pathinfo($cliente->getFoto(), PATHINFO_EXTENSION);
            $zip->adicionarArquivo($dir, 'foto' . ($extensao === '' ? '' : '.' . $extensao), static fn () => ChavesDeCliente::foto($cliente));
        }

        foreach ($this->anexosProprios($cliente, $tenant) as $documento) {
            if ($zip->adicionarArquivo($dir, $documento->getNomeOriginal(), static fn () => ChavesDeCliente::documento($documento), $documento->getTamanhoBytes()) !== null) {
                $zip->contar('Documentos de clientes');
            }
        }
        foreach ($this->vinculadosVisiveis($cliente, $usuario, $tenant, $pastasOcultas, $abasOcultas) as $documentoDaPasta) {
            if ($zip->adicionarArquivo($dir, $documentoDaPasta->getNomeOriginal(), static fn () => ChavesDePasta::documento($documentoDaPasta), $documentoDaPasta->getTamanhoBytes()) !== null) {
                $zip->contar('Documentos de clientes');
            }
        }
    }

    /** @return list<ClienteDocumento> os anexos com arquivo próprio (o vinculado nasce sem `caminho_arquivo`) */
    private function anexosProprios(Cliente $cliente, Tenant $tenant): array
    {
        return array_values($this->em->createQueryBuilder()
            ->select('d')->from(ClienteDocumento::class, 'd')
            ->andWhere('d.cliente = :cliente')->andWhere('d.tenant = :tenant')->andWhere('d.pastaDocumento IS NULL')
            ->setParameter('cliente', $cliente->getId())->setParameter('tenant', $tenant)
            ->orderBy('d.id')->getQuery()->getResult());
    }

    /**
     * Os documentos de pasta que o cliente tem VINCULADOS (o arquivo é o da pasta), como na tela
     * (`DocumentosVisiveisDoCadastro`) mas sem sessão: o documento da pasta vivo (a consulta parte dele, sem carregar o
     * proxy que falha na lixeira), a pasta fora da lixeira, que a pessoa vê (cadeado, escopo e `resources.pasta.view`),
     * com a aba Documentos visível e, sendo contrato, o Financeiro visível. O que não passa nem aparece no LEIA-ME.
     *
     * @param list<int>                    $pastasOcultas
     * @param array<int, list<AbaDaPasta>> $abasOcultas
     *
     * @return list<PastaDocumento>
     */
    private function vinculadosVisiveis(Cliente $cliente, User $usuario, Tenant $tenant, array $pastasOcultas, array $abasOcultas): array
    {
        /** @var list<PastaDocumento> $candidatos */
        $candidatos = $this->em->createQueryBuilder()
            ->select('pd', 'p')->from(PastaDocumento::class, 'pd')
            ->join('pd.pasta', 'p')
            ->join(ClienteDocumento::class, 'd', 'WITH', 'd.pastaDocumento = pd')
            ->andWhere('d.cliente = :cliente')->andWhere('d.tenant = :tenant')
            ->andWhere('pd.tenant = :tenant')->andWhere('p.tenant = :tenant')
            ->andWhere('pd.excluidoEm IS NULL')->andWhere('p.excluidaEm IS NULL')
            ->setParameter('cliente', $cliente->getId())->setParameter('tenant', $tenant)
            ->orderBy('d.id')->getQuery()->getResult();

        return array_values(array_filter($candidatos, function (PastaDocumento $pd) use ($usuario, $tenant, $pastasOcultas, $abasOcultas): bool {
            $pastaId = (int) $pd->getPasta()?->getId();
            $abas    = array_map(static fn (AbaDaPasta $a): string => $a->value, $abasOcultas[$pastaId] ?? []);

            return !in_array($pastaId, $pastasOcultas, true)
                && !in_array(AbaDaPasta::Documentos->value, $abas, true)
                && VisibilidadeDasAbasDaPasta::documentoVisivel($pd->getCategoria(), $abas)
                && $this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW);
        }));
    }
}
