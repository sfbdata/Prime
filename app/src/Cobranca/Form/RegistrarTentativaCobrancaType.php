<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\RegistrarTentativaCobrancaInput;
use App\Cobranca\Enum\CanalContato;
use App\Cobranca\Enum\ResultadoContato;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Registrar um CONTATO de cobrança no histórico do Caso. `casoId` vem da rota; `dataContato` é
 * pré-preenchida com "agora" pelo controller. A única escrita é o evento de histórico.
 */
final class RegistrarTentativaCobrancaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dataContato', DateTimeType::class, [
                'label' => 'Data e hora do contato',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('canal', EnumType::class, [
                'class' => CanalContato::class,
                'label' => 'Canal',
                'placeholder' => 'Selecione o canal',
                'choice_label' => fn (CanalContato $c) => $c->label(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('resultado', EnumType::class, [
                'class' => ResultadoContato::class,
                // Só as opções selecionáveis (sem "Prometeu pagar" — ajuste 2026-07): ninguém escolhe
                // esse resultado em contato novo, mas contatos antigos gravados com ele continuam
                // renderizando via label() no histórico.
                'choices' => ResultadoContato::selecionaveis(),
                'label' => 'Resultado',
                'choice_label' => fn (ResultadoContato $r) => $r->label(),
                'attr' => ['class' => 'form-select'],
            ])
            // Relato é campo NARRATIVO (SPEC UX §11/§12) — ganha a mesma barra de formatação da
            // anotação. Canal, data e resultado seguem estruturados, sem editor: são etiquetas que o
            // relatório conta, não texto corrido.
            ->add('observacao', TextareaType::class, [
                'label' => 'Relato do Atendimento (opcional)',
                'required' => false,
                'attr' => [
                    'data-editor-rico' => true,
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'O que o devedor disse, o que ficou combinado, o contexto do contato',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrarTentativaCobrancaInput::class,
        ]);
    }
}
