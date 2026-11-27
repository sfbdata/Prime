<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\DadosDoConvenio;
use App\Master\DTO\SalvarConvenioInput;
use App\Master\Entity\Convenio;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\ConvenioRepository;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Master\Service\CodigoDeConvenio;
use App\Master\Service\FimDoConvenio;
use App\Master\Service\SorteioDeCodigoDeConvite;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Cria ou edita um convênio (spec docs/specs/convenios.md, §7.3).
 *
 * Quem dispara: o Master, na lista de convênios do painel (ROLE_SUPER_ADMIN no controller).
 * Criar: prova por código de convite (D8: outras instituições só por código), com a `chave` tirada do nome e o código
 * sorteado (8 letras e números, único). Editar: `chave` e `prova` ficam; "Gerar outro" sorteia outro código e digitar um código novo troca para ele (o anterior deixa
 * de valer para novos usos). A `instituicao_chave` sai do nome da instituição: agrupa para o desligar por instituição.
 * Grava no escopo de plataforma (Convenio é Auditavel). Os vínculos acompanham pela tarefa diária.
 * Recusa (422): dado fora da regra, convênio que não existe.
 */
final class SalvarConvenioUseCase
{
    private const TENTATIVAS_DE_CODIGO = 20;

    public function __construct(
        private readonly ConvenioRepository $convenios,
        private readonly ConvenioVinculoRepository $vinculos,
        private readonly SorteioDeCodigoDeConvite $sorteio,
        private readonly SluggerInterface $slugger,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @return array{convenio: Convenio, criado: bool} */
    public function executar(SalvarConvenioInput $input): array
    {
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $dados = $this->dados($input);
        if ($input->id === null) {
            $convenio = Convenio::novoPorCodigo($this->chaveNova($dados->nome), $dados, $this->codigoEscolhido($input->codigo) ?? $this->codigoNovo(), $input->ator, $agora);
        } else {
            $convenio = $this->convenios->find($input->id) ?? throw new ConvenioRecusado('Convênio não encontrado.');
            $this->exigirSemCorteSemAviso($convenio, $dados, $agora);
            $convenio->editar($dados, $input->ator, $agora);
            $escolhido = $this->codigoEscolhido($input->codigo, $convenio->getCodigoConvite());
            if ($escolhido !== null) {
                $convenio->trocarCodigo($escolhido, $input->ator, $agora);
            } elseif ($input->gerarOutroCodigo) {
                $convenio->trocarCodigo($this->codigoNovo(), $input->ator, $agora);
            }
        }
        try {
            $this->escopoDePlataforma->executar(fn () => $this->convenios->salvar($convenio, flush: true));
        } catch (UniqueConstraintViolationException) {
            // Dois saves do mesmo código ao mesmo tempo: a conferência acima passou nos dois e o índice único recusou o segundo.
            throw new ConvenioRecusado('Este código já é de outro convênio. Escolha outro.');
        }

        return ['convenio' => $convenio, 'criado' => $input->id === null];
    }

    private function dados(SalvarConvenioInput $input): DadosDoConvenio
    {
        $abrangencia = AbrangenciaDoConvenio::tryFrom($input->abrangencia) ?? throw new ConvenioRecusado('Escolha onde o convênio vale.');
        $beneficio = BeneficioDoConvenio::tryFrom($input->beneficio) ?? throw new ConvenioRecusado('Escolha o benefício do convênio.');
        $valor = $beneficio === BeneficioDoConvenio::Gratuidade ? 0 : ($input->beneficioValor ?? throw new ConvenioRecusado('Valor do benefício inválido.'));

        return new DadosDoConvenio(
            $input->nome,
            $input->instituicao,
            $this->slug($input->instituicao),
            $abrangencia,
            $abrangencia === AbrangenciaDoConvenio::Uf ? ($input->uf ?? '') : null,
            $beneficio,
            $valor,
            $input->textoSelo,
            $this->data($input->validoDe) ?? throw new ConvenioRecusado('Informe o início da validade (AAAA-MM-DD).'),
            $this->data($input->validoAte),
            $input->limiteDeUsos,
        );
    }

    /**
     * Benefício real de quem já está vinculado só termina com aviso (§6.2): editar não troca o benefício, não baixa o
     * valor, não muda onde vale e não antecipa a validade para antes de 30 dias. Para isso, desligar com aviso.
     */
    private function exigirSemCorteSemAviso(Convenio $convenio, DadosDoConvenio $dados, \DateTimeImmutable $agora): void
    {
        if (!$convenio->getBeneficio()->ehReal() || $this->vinculos->abertosDosConvenios([(int) $convenio->getId()]) === []) {
            return;
        }
        $corta = $dados->beneficio !== $convenio->getBeneficio()
            || $dados->beneficioValor < $convenio->getBeneficioValor()
            || $dados->abrangencia !== $convenio->getAbrangencia()
            || $dados->uf !== $convenio->getUf();
        $ateNovo = $dados->validoAte?->format('Y-m-d');
        $ateAntigo = $convenio->getValidoAte()?->format('Y-m-d');
        $antecipa = $ateNovo !== null && ($ateAntigo === null || $ateNovo < $ateAntigo) && $ateNovo < FimDoConvenio::prazoMinimo($agora)->format('Y-m-d');
        if ($corta || $antecipa) {
            throw new ConvenioRecusado('Este convênio tem contas vinculadas com benefício real: para reduzir ou encerrar o benefício, desligue com aviso de 30 dias.');
        }
    }

    private function data(?string $texto): ?\DateTimeImmutable
    {
        if ($texto === null) {
            return null;
        }
        $dia = \DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
        if ($dia === false || $dia->format('Y-m-d') !== $texto) {
            throw new ConvenioRecusado('Data inválida. Use AAAA-MM-DD.');
        }

        return $dia;
    }

    private function slug(string $texto): string
    {
        return substr($this->slugger->slug($texto)->lower()->toString(), 0, 36);
    }

    /** A chave fixa do convênio novo, do nome; repetida ganha um número no fim. */
    private function chaveNova(string $nome): string
    {
        $base = $this->slug($nome);
        if ($base === '') {
            throw new ConvenioRecusado('Preencha nome, instituição e selo do convênio.');
        }
        $chave = $base;
        for ($n = 2; $this->convenios->existeChave($chave); ++$n) {
            $chave = $base . '-' . $n;
        }

        return $chave;
    }

    /**
     * O código que o Master digitou, na mesma forma que a conta usa ao aplicar. Null: não digitou (sorteia ou mantém) ou
     * digitou o que o convênio já tem.
     */
    private function codigoEscolhido(?string $digitado, ?string $atual = null): ?string
    {
        if ($digitado === null) {
            return null;
        }
        $codigo = CodigoDeConvenio::normalizar($digitado);
        if ($codigo === '' || $codigo === $atual) {
            return null;
        }
        if (!CodigoDeConvenio::valido($codigo)) {
            throw new ConvenioRecusado('O código deve ter de 4 a 16 letras ou números, sem acento nem símbolo.');
        }
        if ($this->convenios->existeCodigo($codigo)) {
            throw new ConvenioRecusado('Este código já é de outro convênio. Escolha outro.');
        }

        return $codigo;
    }

    private function codigoNovo(): string
    {
        for ($i = 0; $i < self::TENTATIVAS_DE_CODIGO; ++$i) {
            $codigo = $this->sorteio->sortear();
            if (!$this->convenios->existeCodigo($codigo)) {
                return $codigo;
            }
        }

        throw new \RuntimeException('Não foi possível sortear um código de convite livre.');
    }
}
