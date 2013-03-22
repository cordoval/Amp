<?php

namespace Amp\Async\Processes;

use Amp\Async\ProcedureException;

class WorkerService {
    
    private $parser;
    private $writer;
    private $buffer;
    private $serializeWorkload = TRUE;
    
    function __construct(FrameParser $parser, FrameWriter $writer) {
        $this->parser = $parser;
        $this->writer = $writer;
        
        ob_start();
    }
    
    function onReadable() {
        if (!$frameArr = $this->parser->parse()) {
            return;
        }
        
        list($isFin, $rsv, $opcode, $payload) = $frameArr;
        
        $this->buffer .= $payload;
        
        if ($isFin) {
            
            $procedureDelimiterPos = strpos($this->buffer, ',');
            $procedure = substr($this->buffer, 0, $procedureDelimiterPos);
            $workload  = substr($this->buffer, $procedureDelimiterPos + 1);
            $workload  = $this->serializeWorkload ? unserialize($workload) : $workload;
            
            try {
                $result = call_user_func_array($procedure, $workload);
                $result = $this->serializeWorkload ? serialize($result) : $result;
                
                $opcode = Frame::OP_DATA;
            } catch (\Exception $e) {
                $result = new ProcedureException(
                    "Uncaught exception encountered while invoking {$procedure}",
                    NULL,
                    $e
                );
                $opcode = Frame::OP_ERROR;
            }
            
            ob_clean();
            
            $this->buffer = '';
            
            $frame = new Frame($fin = 1, $rsv = 0, $opcode, $result);
            
            if (!$this->writer->write($frame)) {
                while (!$this->writer->write());
            }
        }
    }
    
}

