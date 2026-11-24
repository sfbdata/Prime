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
use App\Master\Service\SorteioDeCodigoDeConvite;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Cria ou edita um convênio (spec docs/specs/convenios.md, §7.3).
 *
 * Quem dispara: o Master, na lista de convênios do painel (ROLE_SUPER_ADMIN no controller).
 * Criar: prova por código de convite (D8: outras instituições só por código), com a `chave` tirada do nome e o código
 * sorteado (8 letras e números, único). Editar: `chave` e `prova` ficam; "Gerar outro" troca o código (o anterior deixa
 * de valer para novos usos). A `instituicao_chave` sai do nome da instituição: agrupa para o desligar por instituição.
 * Grava no escopo de plataforma (Convenio é Auditavel). Os vínculos acompanham pela tarefa diária.
 * Recusa (422): dado fora da regra, convênio que não existe.
 */
final class SalvarConvenioUseCase
{
    private const TENTATIVAS_DE_CODIGO = 20;

    public function __construct(
        private readonly ConvenioRepository $convenios,
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
            $convenio = Convenio::novoPorCodigo($this->chaveNova($dados->nome), $dados, $this->codigoNovo(), $input->ator, $agora);
        } else {
            $convenio = $this->convenios->find($input->id) ?? throw new ConvenioRecusado('Convênio não encontrado.');
            $convenio->editar($dados, $input->ator, $agora);
            if ($input->gerarOutroCodigo) {
                $convenio->trocarCodigo($this->codigoNovo(), $input->ator, $agora);
            }
        }
        $this->escopoDePlataforma->executar(fn () => $this->convenios->salvar($convenio, flush: true));

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
