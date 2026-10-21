<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\DTO\ModeloSalvoOutput;
use App\Comercial\Entity\ModeloDeCampanha;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Novo modelo" (dc L.549): um modelo próprio em blocos, com o conteúdo inicial do dc, gravado e auditado
 * (`modelo_new`). A tela passa a editá-lo.
 */
final class CriarModeloDeCampanhaUseCase
{
    public const MENSAGEM = 'Modelo criado. Monte os blocos e clique em Salvar modelo.';

    public function __construct(
        private readonly ModeloDeCampanhaRepository $modelos,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $por): ModeloSalvoOutput
    {
        $inicial = new MensagemDaCampanha('novo', 'blocos', 'Novo informativo', 'BLUEJUS', 'Título do informativo', blocos: [
            ['t' => 'texto', 'a' => 'Olá, {nome}!', 'b' => ''],
            ['t' => 'botao', 'a' => 'Abrir o BlueJus', 'b' => 'app'],
        ]);
        $agora = $this->relogio->now();
        $modelo = new ModeloDeCampanha('Novo modelo', 'bi-file-earmark-plus', 'blocos', $por, $agora);
        $modelo->preencher($inicial->assunto, $inicial->selo, $inicial->titulo, ModeloDaGrade::conteudoParaGravar($inicial), $agora);
        $this->modelos->salvar($modelo, ModeloDeCampanhaRepository::NOVO, $por);

        return new ModeloSalvoOutput(ModeloDaGrade::deEntidade($modelo), self::MENSAGEM);
    }
}
