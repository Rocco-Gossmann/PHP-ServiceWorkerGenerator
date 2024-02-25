#!/bin/bash

tmux-workspace "ServiceWorkerGenerator" "editor" -c "nvim" \
	-w "server" -c "php -S localhost:7353"	
