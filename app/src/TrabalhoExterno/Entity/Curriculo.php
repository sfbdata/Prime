<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\Enum\SituacaoAcademica;
use App\TrabalhoExterno\Enum\StatusCurriculo;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * O currículo de um usuário externo (spec VS 18.10.26 §4.5): um por usuário, da plataforma e não
 * de um escritório, como a vaga. Não é `TenantAware`: a Central lê o banco inteiro; quem isola é o
 * `LocalizadorDeCurriculo` (o externo só alcança o dele) e a `PoliticaDoCurriculo`.
 *
 * `recebidoEm` é o primeiro envio e não muda; `atualizadoEm` muda a cada edição ou arquivo. Status e
 * nota são da Central e nunca saem para o dono.
 */
#[ORM\Entity(repositoryClass: CurriculoRepository::class)]
#[ORM\Table(name: 'trabalho_externo_curriculo')]
class Curriculo
{
    /** O texto do aceite do formulário (l.282), guardado com a versão a cada envio. */
    public const CONSENTIMENTO = 'Autorizo o BlueJus a guardar meus dados e compartilhá-los apenas com quem publica as vagas a que eu me candidatar (LGPD). Posso excluir meu currículo a qualquer momento.';
    public const VERSAO_DO_CONSENTIMENTO = '1';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(enumType: StatusCurriculo::class, length: 20)]
    private StatusCurriculo $status = StatusCurriculo::Novo;

    #[ORM\Column(type: 'text')]
    private string $nota = '';

    #[ORM\Column(enumType: SituacaoAcademica::class, length: 10)]
    private SituacaoAcademica $situacao;

    /**
     * Identificação, situação acadêmica, preferências e apresentação, como vêm do formulário. Sem
     * coluna por campo: nenhuma consulta filtra por eles no banco (a lista é toda da Central).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $dados = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $areas = [];

    /** @var array<string, array{chave: string, nome: string}> tipo do arquivo → chave no armazenamento e nome original */
    #[ORM\Column(type: 'json')]
    private array $arquivos = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $consentidoEm;

    #[ORM\Column(length: 10)]
    private string $versaoDoConsentimento = self::VERSAO_DO_CONSENTIMENTO;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $recebidoEm;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    private function __construct(
        #[ORM\OneToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        private User $usuario,
        \DateTimeImmutable $agora,
    ) {
        $this->recebidoEm = $agora;
        $this->atualizadoEm = $agora;
        $this->consentidoEm = $agora;
    }

    /** "Salvar currículo" pela primeira vez: entra no banco como "Novo". O DTO já foi validado. */
    public static function criar(User $usuario, DadosDoCurriculo $dados, \DateTimeImmutable $agora): self
    {
        $cv = new self($usuario, $agora);
        $cv->preencher($dados, $agora);
        $cv->consentir($agora);

        return $cv;
    }

    /**
     * "Editar currículo": o status e a nota da Central ficam como estão. O anexo do bloco que a nova
     * situação esconde sai como os campos de texto dele (carteira da OAB fora de adv e estOab,
     * comprovante do curso para adv); as chaves voltam para quem chama apagar depois do COMMIT. O
     * consentimento só é regravado se o texto do aceite mudou de versão.
     *
     * @return list<string> chaves dos arquivos descartados
     */
    public function editar(DadosDoCurriculo $dados, \DateTimeImmutable $agora): array
    {
        $this->preencher($dados, $agora);
        if ($this->versaoDoConsentimento !== self::VERSAO_DO_CONSENTIMENTO) {
            $this->consentir($agora);
        }

        return array_values(array_filter([
            $this->situacao->temOab() ? null : $this->removerArquivo(TipoArquivoDoCurriculo::Oab, $agora),
            $this->situacao->temCurso() ? null : $this->removerArquivo(TipoArquivoDoCurriculo::Matricula, $agora),
        ]));
    }

    private function consentir(\DateTimeImmutable $agora): void
    {
        $this->consentidoEm = $agora;
        $this->versaoDoConsentimento = self::VERSAO_DO_CONSENTIMENTO;
    }

    public function mudarStatus(StatusCurriculo $status): void
    {
        $this->status = $status;
    }

    public function anotar(string $nota): void
    {
        $this->nota = mb_substr(trim($nota), 0, 5000);
    }

    /** Guarda o arquivo novo e devolve a chave do que ele substitui (para apagar depois do COMMIT). */
    public function anexarArquivo(TipoArquivoDoCurriculo $tipo, string $chave, string $nome, \DateTimeImmutable $agora): ?string
    {
        $anterior = $this->chaveDoArquivo($tipo);
        $this->arquivos[$tipo->value] = ['chave' => $chave, 'nome' => mb_substr($nome, 0, 255)];
        $this->atualizadoEm = $agora;

        return $anterior;
    }

    /** Tira o arquivo e devolve a chave dele (para apagar depois do COMMIT); null se não havia. */
    public function removerArquivo(TipoArquivoDoCurriculo $tipo, \DateTimeImmutable $agora): ?string
    {
        $anterior = $this->chaveDoArquivo($tipo);
        if ($anterior !== null) {
            unset($this->arquivos[$tipo->value]);
            $this->atualizadoEm = $agora;
        }

        return $anterior;
    }

    private function preencher(DadosDoCurriculo $d, \DateTimeImmutable $agora): void
    {
        $this->situacao = $d->situacao ?? throw new \LogicException('O currículo exige a situação: valide o DTO antes.');
        $this->dados = [
            'nome' => $d->nome, 'email' => $d->email, 'tel' => $d->tel, 'insta' => $d->insta,
            'oabNum' => $d->oabNum, 'oabUf' => $d->oabUf, 'inst' => $d->inst, 'sem' => $d->sem,
            'cargo' => $d->cargo, 'cidade' => $d->cidade, 'uf' => $d->uf, 'modalidade' => $d->modalidade,
            'anos' => $d->anos, 'resumo' => $d->resumo, 'pretensao' => $d->pretensao, 'vol' => $d->vol,
            'tituloP' => $d->tituloP, 'linkedin' => $d->linkedin, 'lattes' => $d->lattes, 'habs' => $d->habs,
            'exps' => $d->exps, 'forms' => $d->forms, 'idis' => $d->idis,
        ];
        $this->areas = $d->areas;
        $this->atualizadoEm = $agora;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodigo(): string
    {
        return 'CV-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public static function idDoCodigo(string $codigo): ?int
    {
        return preg_match('/^CV-(\d{1,9})$/', $codigo, $m) === 1 ? (int) $m[1] : null;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getStatus(): StatusCurriculo
    {
        return $this->status;
    }

    public function getNota(): string
    {
        return $this->nota;
    }

    public function getSituacao(): SituacaoAcademica
    {
        return $this->situacao;
    }

    /** Um campo de texto do formulário (nome, email, tel, cidade…); vazio se não informado. */
    public function texto(string $campo): string
    {
        $v = $this->dados[$campo] ?? '';

        return is_scalar($v) ? (string) $v : '';
    }

    public function getAnos(): ?int
    {
        $anos = $this->dados['anos'] ?? null;

        return is_int($anos) ? $anos : null;
    }

    /** @return list<array<string, string>> experiências (`exps`), formações (`forms`) ou idiomas (`idis`) */
    public function itens(string $lista): array
    {
        $itens = $this->dados[$lista] ?? [];

        return is_array($itens) ? array_values(array_filter($itens, 'is_array')) : [];
    }

    /** @return list<string> */
    public function getAreas(): array
    {
        return $this->areas;
    }

    public function temArquivo(TipoArquivoDoCurriculo $tipo): bool
    {
        return isset($this->arquivos[$tipo->value]);
    }

    public function chaveDoArquivo(TipoArquivoDoCurriculo $tipo): ?string
    {
        return $this->arquivos[$tipo->value]['chave'] ?? null;
    }

    public function nomeDoArquivo(TipoArquivoDoCurriculo $tipo): ?string
    {
        return $this->arquivos[$tipo->value]['nome'] ?? null;
    }

    /** @return list<string> as chaves de todos os arquivos, para apagar depois da exclusão */
    public function chavesDosArquivos(): array
    {
        return array_values(array_map(static fn (array $a): string => $a['chave'], $this->arquivos));
    }

    public function getConsentidoEm(): \DateTimeImmutable
    {
        return $this->consentidoEm;
    }

    public function getVersaoDoConsentimento(): string
    {
        return $this->versaoDoConsentimento;
    }

    public function getRecebidoEm(): \DateTimeImmutable
    {
        return $this->recebidoEm;
    }

    public function getAtualizadoEm(): \DateTimeImmutable
    {
        return $this->atualizadoEm;
    }
}
