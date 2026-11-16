<?php

declare(strict_types=1);

namespace App\Conector\Entity;

use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Repository\ClienteDoConectorRepository;
use App\Shared\Attribute\SemIsolamentoDeTenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * O app de I.A. registrado no servidor de autorização por CIMD (`docs/specs/mcp-remoto-oauth.md`, 3.4 e 13.2): o Claude
 * e o ChatGPT, com `identificador` igual à URL do documento de metadados. `dcr` e `pre_registrado` ficam reservados no
 * `tipo`, sem uso no piloto.
 *
 * **Global por natureza, exceção declarada à regra "toda consulta filtra tenant":** o ChatGPT é o mesmo cliente para
 * todos os escritórios, e nenhum dado de escritório passa por esta tabela. O escritório de cada conexão mora no
 * consentimento, na concessão, no código e no token. Por isso não tem `tenant_id` e a purga não a toca.
 *
 * `nome` é o `client_name` do documento, autodeclarado: a tela de consentimento mostra o host do `identificador` (9.4).
 */
#[ORM\Entity(repositoryClass: ClienteDoConectorRepository::class)]
#[ORM\Table(name: 'conector_ia_cliente')]
#[ORM\UniqueConstraint(name: 'uniq_conector_ia_cliente_identificador', columns: ['identificador'])]
#[SemIsolamentoDeTenant('Cliente OAuth global por natureza (o mesmo ChatGPT para todos os escritórios); nenhum dado de escritório passa por ele, o escritório da conexão mora no consentimento e na concessão')]
class ClienteDoConector
{
    public const IDENTIFICADOR_MAXIMO = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'metadados_buscados_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $metadadosBuscadosEm = null;

    #[ORM\Column(name: 'metadados_validos_ate', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $metadadosValidosAte = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $ativo = true;

    /** @var list<string> */
    #[ORM\Column(name: 'redirect_uris', type: 'json')]
    private array $redirectUris;

    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        #[ORM\Column(length: self::IDENTIFICADOR_MAXIMO)]
        private string $identificador,
        #[ORM\Column(length: 20, enumType: TipoDeClienteDoConector::class)]
        private TipoDeClienteDoConector $tipo,
        #[ORM\Column(length: 255)]
        private string $nome,
        array $redirectUris,
        #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm,
    ) {
        if (trim($identificador) === '' || mb_strlen($identificador) > self::IDENTIFICADOR_MAXIMO) {
            throw new \InvalidArgumentException('O identificador do cliente precisa ter de 1 a 2000 caracteres.');
        }
        $this->redirectUris = $this->exigirRedirects($redirectUris);
    }

    /**
     * Grava o documento de metadados buscado de novo (CIMD) e até quando ele vale pelo cache HTTP.
     *
     * @param list<string> $redirectUris
     */
    public function renovarMetadados(string $nome, array $redirectUris, \DateTimeImmutable $buscadoEm, \DateTimeImmutable $validoAte): void
    {
        $this->nome = $nome;
        $this->redirectUris = $this->exigirRedirects($redirectUris);
        $this->metadadosBuscadosEm = $buscadoEm;
        $this->metadadosValidosAte = $validoAte;
    }

    public function desativar(): void
    {
        $this->ativo = false;
    }

    /**
     * @param array<mixed> $redirectUris
     *
     * @return list<string>
     */
    private function exigirRedirects(array $redirectUris): array
    {
        if ($redirectUris === [] || !array_is_list($redirectUris)) {
            throw new \InvalidArgumentException('O cliente precisa de pelo menos um endereço de retorno.');
        }
        foreach ($redirectUris as $uri) {
            if (!\is_string($uri) || trim($uri) === '') {
                throw new \InvalidArgumentException('Endereço de retorno inválido.');
            }
        }

        return $redirectUris;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentificador(): string
    {
        return $this->identificador;
    }

    public function getTipo(): TipoDeClienteDoConector
    {
        return $this->tipo;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    /** @return list<string> */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }

    public function getMetadadosBuscadosEm(): ?\DateTimeImmutable
    {
        return $this->metadadosBuscadosEm;
    }

    public function getMetadadosValidosAte(): ?\DateTimeImmutable
    {
        return $this->metadadosValidosAte;
    }

    public function isAtivo(): bool
    {
        return $this->ativo;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
