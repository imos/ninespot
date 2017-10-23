build: vendor/autoload.php
	php phar-composer.phar build .
	chmod +x ninespot.phar
.PHONY: build

vendor/autoload.php: composer.json
	php composer.phar install

clean:
	rm -rf vendor
	rm -rf ninespot.phar
.PHONY: clean

update:
	php composer.phar update
.PHONY: update
