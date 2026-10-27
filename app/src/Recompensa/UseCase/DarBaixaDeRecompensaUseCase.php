<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\Armazenamento\ChavesDeRecompensa;
use App\Recompensa\DTO\DarBaixaDeRecompensaInput;
use App\Recompensa\DTO\IndicadorDeRecompensaOutput;
use App\Recompensa\Entity\LancamentoDeRecompensa;
use App\Recompensa\Exception\IndicadorNaoEncontrado;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Shared\Http\FonteDeUploadHttp;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "Dar baixa" no pagamento de uma recompensa (menu ⋮ › "Dar baixa"; desenho 05 Perfis 1.2 VS 25.10.26, diálogo `pagar`;
 * spec perfis-1.2-vs-25-10-recompensas-e-planos, F4). Risco ALTO: dinheiro. O Master pagou FORA do sistema (PIX,
 * transferência, desconto ou crédito de espaço) e registra aqui; o BlueJus não movimenta dinheiro.
 *
 * Quem dispara: o Master (ROLE_SUPER_ADMIN no controller). Dado de plataforma, sem tenant: o indicador é qualquer usuário
 * que já indicou; o id vem da URL e é conferido contra as indicações.
 * Fluxo principal: sob a trava das indicações do usuário (a mesma do bloquear e do apagar), confere as regras sobre o
 * painel recalculado e grava um lançamento `pago` (valor positivo, forma, comprovante opcional). Dois pedidos
 * simultâneos passam um de cada vez: nunca se paga acima do saldo.
 * Regras (422, nada gravado): indicador liberado, não bloqueado, sem suspeita pendente, com nome e documento de quem
 * recebe, valor maior que zero e até o saldo.
 * Idempotência: a chave é única. Repetir a mesma chave com o mesmo indicador e valor responde como sucesso, sem gravar
 * de novo nem guardar outro arquivo; mesma chave com outro indicador, valor ou tipo de lançamento recusa. Forma e arquivo
 * diferentes na mesma chave contam como repetição (vale o primeiro pedido): é duplo clique, não edição.
 * Comprovante: PDF, PNG, JPG ou WEBP até 5 MB, com o tipo lido do CONTEÚDO. O arquivo só é gravado depois de as regras
 * passarem e sai se o COMMIT comprovadamente não acontecer ({@see TransacaoComArquivoNovo}).
 * Auditoria: LancamentoDeRecompensa é Auditavel; cada baixa entra na trilha do Master.
 */
final class DarBaixaDeRecompensaUseCase
{
    public const COMPROVANTE_TAMANHO_MAXIMO = 5 * 1024 * 1024;

    /** Extensão aceita => tipos que o conteúdo pode ter. */
    public const COMPROVANTE_TIPOS_POR_EXTENSAO = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
    ];

    public function __construct(
        private readonly LancamentoDeRecompensaRepository $lancamentos,
        private readonly IndicacaoRepository $indicacoes,
        private readonly ConsultarPainelDeRecompensasUseCase $painel,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    /**
     * @throws IndicadorNaoEncontrado
     * @throws RecompensaRecusada
     */
    public function executar(DarBaixaDeRecompensaInput $input): void
    {
        if ($this->indicacoes->contarDoUsuario($input->userId) === 0) {
            throw new IndicadorNaoEncontrado('Indicador não encontrado.');
        }
        $usuario = $this->em->find(User::class, $input->userId) ?? throw new IndicadorNaoEncontrado('Indicador não encontrado.');
        $extensao = $input->comprovante === null ? null : self::exigirComprovanteAceito($input->comprovante);

        $existente = null;
        $recusa = null;
        $novaChave = null;
        // Closures comuns com `use (&...)`: uma `fn` copiaria as variáveis e o resultado nunca chegaria aqui fora.
        $trabalho = function () use ($input, $usuario, $extensao, &$existente, &$recusa, &$novaChave): void {
            $this->lancamentos->travarChave($input->chaveDeIdempotencia);
            $existente = $this->lancamentos->buscarPorChave($input->chaveDeIdempotencia);
            if ($existente !== null) {
                return;
            }
            $recusa = $this->regraQueRecusa($input);
            if ($recusa !== null) {
                return;
            }
            $novaChave = $this->gravarPagamento($input, $usuario, $extensao);
        };
        $this->escopoDePlataforma->executar(fn () => $this->transacao->executar(
            fn () => $this->indicacoes->emTransacaoTravada($input->userId, $trabalho),
            static function () use (&$novaChave): array {
                return $novaChave instanceof ChaveDeArquivo ? [$novaChave] : [];
            },
            'recompensa_comprovante',
        ));

        // Fora da transação: uma exceção dentro dela fecharia o EntityManager.
        if ($recusa !== null) {
            throw new RecompensaRecusada($recusa);
        }
        if ($existente !== null && ($existente->getUser()?->getId() !== $usuario->getId() || $existente->getValorCentavos() !== $input->valorCentavos || $existente->getTipo() !== LancamentoDeRecompensa::TIPO_PAGO)) {
            throw new RecompensaRecusada('Este pedido já foi usado para outro lançamento. Feche a janela e abra de novo.');
        }
    }

    /** Guarda o comprovante (se veio) e o lançamento `pago`, já com as regras conferidas; devolve a chave nova do arquivo. */
    private function gravarPagamento(DarBaixaDeRecompensaInput $input, User $usuario, ?string $extensao): ?ChaveDeArquivo
    {
        $armazenado = $extensao === null || $input->comprovante === null ? null : FonteDeUploadHttp::de($input->comprovante)
            ->gravarEm($this->armazenamento, ChavesDeRecompensa::novoComprovante($extensao));
        $this->lancamentos->salvar(LancamentoDeRecompensa::pagamento(
            $usuario,
            $input->valorCentavos,
            $input->forma,
            $input->observacao,
            $armazenado?->chave->nome,
            $input->comprovante === null ? null : self::nomeOriginal($input->comprovante),
            $input->chaveDeIdempotencia,
            $input->ator,
            $this->relogio->now(),
        ));

        return $armazenado?->chave;
    }

    /** A primeira regra de negócio que impede a baixa, sobre o painel recalculado DENTRO da trava; nulo se pode pagar. */
    private function regraQueRecusa(DarBaixaDeRecompensaInput $input): ?string
    {
        $linha = null;
        foreach ($this->painel->executar()->indicadores as $candidata) {
            if ($candidata->userId === $input->userId) {
                $linha = $candidata;
                break;
            }
        }

        return match (true) {
            $linha === null => 'Indicador não encontrado.',
            !$linha->liberado => sprintf('Ainda não liberado: precisa de %d escritório(s) ativo(s) com pastas (tem %d).', $linha->minEscritorios, $linha->ativos),
            $linha->bloqueado => 'Recompensa bloqueada para este usuário.',
            $linha->suspeitas > 0 => 'Há contas em análise para este indicador. Revise as suspeitas antes de dar baixa.',
            $linha->recebimento === null => 'Cadastre o nome e o CPF ou CNPJ de quem recebe (Dados para pagamento) antes de dar baixa.',
            $input->valorCentavos > $linha->saldoCentavos => sprintf('Informe um valor maior que zero e até o saldo de R$ %s.', self::reais($linha)),
            default => null,
        };
    }

    private static function reais(IndicadorDeRecompensaOutput $linha): string
    {
        return number_format($linha->saldoCentavos / 100, 2, ',', '.');
    }

    /**
     * @return string a extensão aceita
     *
     * @throws RecompensaRecusada
     */
    private static function exigirComprovanteAceito(UploadedFile $arquivo): string
    {
        if (!$arquivo->isValid()) {
            throw new RecompensaRecusada('O envio do comprovante falhou. Tente de novo (até 5 MB).');
        }
        $tamanho = (int) $arquivo->getSize();
        if ($tamanho > self::COMPROVANTE_TAMANHO_MAXIMO) {
            throw new RecompensaRecusada('O comprovante passa de 5 MB.');
        }
        if ($tamanho === 0) {
            throw new RecompensaRecusada('O comprovante está vazio.');
        }
        $extensao = strtolower(pathinfo($arquivo->getClientOriginalName(), \PATHINFO_EXTENSION));
        $mime = strtolower((string) $arquivo->getMimeType());
        if (!in_array($mime, self::COMPROVANTE_TIPOS_POR_EXTENSAO[$extensao] ?? [], true)) {
            throw new RecompensaRecusada('Anexe o comprovante em PDF, PNG, JPG ou WEBP; o conteúdo precisa ser do tipo da extensão.');
        }

        return $extensao === 'jpeg' ? 'jpg' : $extensao;
    }

    /** O nome que o Master deu ao arquivo, sem caminho nem caractere de controle, com até 255 caracteres. */
    private static function nomeOriginal(UploadedFile $arquivo): string
    {
        $nome = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $arquivo->getClientOriginalName())));

        return mb_substr(trim($nome) !== '' ? trim($nome) : 'comprovante', 0, 255);
    }
}
